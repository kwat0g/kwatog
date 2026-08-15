<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\HashIdFilter;
use App\Common\Services\SettingsService;
use App\Common\Services\TaxPolicyService;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\DeliverySchedule;
use App\Modules\B2B\Events\SupplierInvoiceSubmitted;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\B2B\Enums\SupplierAgingBucket;
use App\Modules\B2B\Models\PortalShippingDocument;
use App\Common\Services\SystemUserResolver;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Quality\Models\PpapSubmission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Business logic for the Supplier B2B Portal.
 *
 * Every method receives the owning vendor_id (and optionally the portal user id)
 * so that row-level scoping is guaranteed — the controller resolves the
 * authenticated portal user and passes `$user->vendor_id`. This service NEVER
 * reads the auth guard directly; scoping is always explicit.
 */
class SupplierPortalService
{
    public function __construct(
        private readonly BillService $bills,
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly SystemUserResolver $systemUser,
        private readonly SettingsService $settings,
        private readonly TaxPolicyService $taxPolicy,
    ) {}

    /* ─── Dashboard ──────────────────────────────────────────────── */

    public function dashboard(int $vendorId): array
    {
        $openPoCount = PurchaseOrder::where('vendor_id', $vendorId)
            ->whereIn('status', [PurchaseOrderStatus::Approved->value, PurchaseOrderStatus::Sent->value])->count();

        $pendingDeliveryCount = PurchaseOrder::where('vendor_id', $vendorId)
            ->where('status', PurchaseOrderStatus::Sent->value)->count();

        $unpaidInvoiceCount = Bill::where('vendor_id', $vendorId)
            ->whereIn('status', [BillStatus::Unpaid->value, BillStatus::Partial->value])->count();

        $totalUnpaid = Bill::where('vendor_id', $vendorId)
            ->whereIn('status', [BillStatus::Unpaid->value, BillStatus::Partial->value])->sum('balance');

        $recentPos = PurchaseOrder::where('vendor_id', $vendorId)
            ->orderByDesc('created_at')->limit(5)->get();

        $recentInvoices = Bill::where('vendor_id', $vendorId)
            ->with('purchaseOrder:id,po_number')
            ->orderByDesc('created_at')->limit(5)->get();

        return [
            'open_po_count' => $openPoCount,
            'pending_delivery_count' => $pendingDeliveryCount,
            'unpaid_invoice_count' => $unpaidInvoiceCount,
            'total_unpaid_amount' => number_format((float) $totalUnpaid, 2),
            'recent_pos' => $recentPos,
            'recent_invoices' => $recentInvoices,
        ];
    }

    /* ─── Purchase Orders ────────────────────────────────────────── */

    public function purchaseOrders(int $vendorId, array $filters): LengthAwarePaginator
    {
        $query = PurchaseOrder::where('vendor_id', $vendorId)
            ->with(['vendor:id,name', 'items.item:id,code,name,unit_of_measure'])
            ->withCount('goodsReceiptNotes');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $query->where('po_number', 'like', "%{$filters['search']}%");
        }

        $sortField = $filters['sort'] ?? 'created_at';
        $sortDir = $filters['dir'] ?? 'desc';
        $allowed = ['po_number', 'date', 'total_amount', 'status', 'created_at'];
        if (in_array($sortField, $allowed, true)) {
            $query->orderBy($sortField, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        $perPage = min((int) ($filters['per_page'] ?? 25), 100);

        return $query->paginate($perPage);
    }

    public function purchaseOrderDetail(int $vendorId, PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        abort_if($purchaseOrder->vendor_id !== $vendorId, 403);

        $purchaseOrder->load([
            'vendor:id,name,contact_person,email,phone,address',
            'items.item:id,code,name,unit_of_measure',
            'goodsReceiptNotes:id,grn_number,received_date,status',
            'bills:id,bill_number,total_amount,balance,status',
            'purchaseRequest:id,pr_number',
        ]);

        $purchaseOrder->bills->each(function ($bill): void {
            $bill->setAttribute(
                'status_label',
                BillStatus::tryFrom((string) $bill->status)?->label() ?? (string) $bill->status,
            );
        });

        return $purchaseOrder;
    }

    public function acknowledgePo(int $vendorId, PurchaseOrder $purchaseOrder, array $data): PurchaseOrder
    {
        abort_if($purchaseOrder->vendor_id !== $vendorId, 403);

        return $this->systemUser->impersonate(function () use ($purchaseOrder, $data) {
            return DB::transaction(function () use ($purchaseOrder, $data): PurchaseOrder {
                // Route-bound models can be stale when purchasing cancels or
                // sends the PO concurrently. Re-read and lock before allowing
                // the portal to cross the sent boundary; the canonical service
                // owns the durable event, dispatch proof, and GRN trigger.
                $row = PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->id);
                if ($row->status !== PurchaseOrderStatus::Approved) {
                    throw new BusinessRuleException('Only approved purchase orders can be acknowledged.');
                }

                $row->expected_delivery_date = $data['expected_delivery_date'] ?? $row->expected_delivery_date;
                $row->remarks = $data['notes'] ?? $row->remarks;
                $row->save();

                return $this->purchaseOrders->markAsSent($row, 'supplier_portal_acknowledgement');
            });
        });
    }

    public function updateShipment(int $vendorId, PurchaseOrder $purchaseOrder, array $data): PurchaseOrder
    {
        abort_if($purchaseOrder->vendor_id !== $vendorId, 403);

        return $this->systemUser->impersonate(function () use ($purchaseOrder, $data, $vendorId) {
            return DB::transaction(function () use ($purchaseOrder, $data, $vendorId): PurchaseOrder {
                // Shipment updates share the PO row with acknowledgement,
                // cancellation, and receiving. Re-read and lock the
                // authoritative row so a stale portal page cannot overwrite a
                // newer transition or append to an obsolete remarks value.
                $row = PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->id);
                abort_if((int) $row->vendor_id !== $vendorId, 403);

                if (in_array($row->status, [
                    PurchaseOrderStatus::Cancelled,
                    PurchaseOrderStatus::Received,
                    PurchaseOrderStatus::Closed,
                ], true)) {
                    throw new BusinessRuleException('Shipment updates are not allowed for a cancelled, received, or closed purchase order.');
                }

                $estimatedArrival = $data['estimated_arrival'] ?? $row->expected_delivery_date;
                $shippedDate = $data['shipped_date'] ?? null;
                $carrier = trim((string) ($data['carrier'] ?? ''));
                $tracking = trim((string) ($data['tracking_number'] ?? ''));
                $notes = trim((string) ($data['notes'] ?? ''));

                $shipmentDetails = array_values(array_filter([
                    $shippedDate !== null && $shippedDate !== '' ? 'Shipped: '.$shippedDate : null,
                    $carrier !== '' ? 'Carrier: '.$carrier : null,
                    $tracking !== '' ? 'Tracking: '.$tracking : null,
                    $notes !== '' ? 'Notes: '.$notes : null,
                ]));

                $row->expected_delivery_date = $estimatedArrival;
                if ($shipmentDetails !== []) {
                    $previousRemarks = trim((string) $row->remarks);
                    $prefix = $previousRemarks !== '' ? $previousRemarks."\n" : '';
                    $row->remarks = $prefix.'Shipment update: '.implode(' / ', $shipmentDetails);
                }
                $row->save();

                return $row->fresh();
            });
        });
    }

    /* ─── Shipping Documents ─────────────────────────────────────── */

    public function uploadShippingDocument(
        int $vendorId,
        int $portalUserId,
        PurchaseOrder $purchaseOrder,
        UploadedFile $file,
        array $data,
    ): PortalShippingDocument {
        abort_if($purchaseOrder->vendor_id !== $vendorId, 403);

        $folder = "portal/shipping-docs/{$purchaseOrder->id}";
        $path = $file->store($folder, 'local');
        if ($path === false) {
            throw new \RuntimeException('Unable to store the shipping document.');
        }

        try {
            // Idempotent — a double-click or retried request that re-uploads
            // the same file (same PO + type + filename + size) must not stack
            // a second document row or orphan a second stored file. Lock the
            // PO row to serialize concurrent uploads, then return the existing
            // row and delete the just-stored duplicate. The unique index
            // `portal_shipping_documents_dedup_unique` backs this guard at the
            // DB level.
            return DB::transaction(function () use ($vendorId, $purchaseOrder, $portalUserId, $path, $file, $data): PortalShippingDocument {
                $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->id);
                abort_if((int) $po->vendor_id !== $vendorId, 403);

                $existing = PortalShippingDocument::query()
                    ->where('purchase_order_id', $po->id)
                    ->where('document_type', $data['document_type'])
                    ->where('original_filename', $file->getClientOriginalName())
                    ->where('file_size_bytes', $file->getSize())
                    ->first();

                if ($existing) {
                    Storage::disk('local')->delete($path);

                    return $existing;
                }

                return PortalShippingDocument::create([
                    'purchase_order_id' => $po->id,
                    'document_type' => $data['document_type'],
                    'file_path' => $path,
                    'original_filename' => $file->getClientOriginalName(),
                    'file_size_bytes' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'notes' => $data['notes'] ?? null,
                    'uploaded_by' => $portalUserId,
                    'uploaded_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function shippingDocuments(int $vendorId, PurchaseOrder $purchaseOrder): Collection
    {
        abort_if($purchaseOrder->vendor_id !== $vendorId, 403);

        return PortalShippingDocument::where('purchase_order_id', $purchaseOrder->id)
            ->orderByDesc('uploaded_at')
            ->get();
    }

    public function downloadShippingDocument(int $vendorId, string $hashId): PortalShippingDocument
    {
        $doc = PortalShippingDocument::findOrFail(
            HashIdFilter::decode($hashId, PortalShippingDocument::class),
        );

        $po = $doc->purchaseOrder;
        abort_if(! $po || $po->vendor_id !== $vendorId, 403);

        if (! Storage::disk('local')->exists($doc->file_path)) {
            abort(404, 'File not found.');
        }

        return $doc;
    }

    /* ─── Invoice Submission ─────────────────────────────────────── */

    /**
     * Supplier submits their invoice; creates a draft Bill in Accounts Payable.
     *
     * @return array{bill: Bill, message: string}
     */
    public function submitInvoice(
        int $vendorId,
        int $portalUserId,
        PurchaseOrder $purchaseOrder,
        array $data,
        ?UploadedFile $file = null,
    ): array {
        abort_if($purchaseOrder->vendor_id !== $vendorId, 403);

        $storedPath = null;
        try {
            $result = DB::transaction(function () use ($purchaseOrder, $data, $file, $portalUserId, $vendorId, &$storedPath) {
                // Lock both the source PO and vendor before checking the
                // supplier bill number. This is the idempotency boundary for
                // portal double-submit/retry requests.
                $lockedPurchaseOrder = PurchaseOrder::query()
                    ->lockForUpdate()
                    ->with(['vendor:id,name', 'items.item'])
                    ->findOrFail($purchaseOrder->id);
                abort_if((int) $lockedPurchaseOrder->vendor_id !== $vendorId, 403);
                Vendor::query()->lockForUpdate()->findOrFail($vendorId);

                // Resolve the idempotent result before any mutable setup
                // lookup. A retry must remain safe even if the expense-account
                // configuration changed after the original draft was staged.
                $existing = Bill::query()
                    ->where('vendor_id', $vendorId)
                    ->where('bill_number', $data['bill_number'])
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    if ((int) $existing->purchase_order_id !== (int) $lockedPurchaseOrder->id) {
                        throw new BusinessRuleException(
                            "Bill number '{$data['bill_number']}' is already used for another purchase order."
                        );
                    }

                    return [
                        'bill' => $existing->fresh(),
                        'message' => 'Invoice was already submitted. The existing bill remains in its current review state.',
                    ];
                }

                $defaultAccountHashId = $this->defaultExpenseAccountHashId();
                $items = $lockedPurchaseOrder->items->map(fn ($poItem) => [
                    'expense_account_id' => $defaultAccountHashId,
                    'item_id' => $poItem->item?->hash_id,
                    'description' => $poItem->description,
                    'quantity' => (string) $poItem->quantity,
                    'unit' => $poItem->unit,
                    'unit_price' => (string) $poItem->unit_price,
                ])->toArray();

                if (empty($items)) {
                    // A supplier hitting this saw a generic 500 "Server Error"
                    // page with no clue what to fix. It is a state violation,
                    // not a server fault.
                    throw new BusinessRuleException('This purchase order has no items to bill.');
                }

                $acceptedGrn = GoodsReceiptNote::query()
                    ->where('purchase_order_id', $lockedPurchaseOrder->id)
                    ->where('vendor_id', $vendorId)
                    ->where('status', GrnStatus::Accepted)
                    ->latest('id')
                    ->first();
                if (! $acceptedGrn) {
                    throw new BusinessRuleException('Supplier invoices for stock items require an accepted goods receipt.');
                }

                $systemUser = app(SystemUserResolver::class);

                $bill = $systemUser->impersonate(fn () => $this->bills->createDraft([
                    'bill_number' => $data['bill_number'],
                    'vendor_id' => $lockedPurchaseOrder->vendor->hash_id,
                    'purchase_order_id' => $lockedPurchaseOrder->hash_id,
                    'goods_receipt_note_id' => $acceptedGrn->hash_id,
                    'provenance_type' => 'stock',
                    'date' => $data['date'],
                    'due_date' => $data['due_date'] ?? $data['date'],
                    'is_vatable' => $data['is_vatable'] ?? $this->taxPolicy->isVatRegistered(),
                    'remarks' => $data['remarks'] ?? null,
                    'items' => $items,
                ], User::find($systemUser->id())));

                if ($file) {
                    $folder = "portal/supplier-invoices/{$bill->id}";
                    $storedPath = $file->store($folder, 'local');
                    if ($storedPath === false) {
                        throw new \RuntimeException('Unable to store the supplier invoice.');
                    }

                    PortalShippingDocument::create([
                        'purchase_order_id' => $lockedPurchaseOrder->id,
                        'bill_id' => $bill->id,
                        'document_type' => 'supplier_invoice',
                        'file_path' => $storedPath,
                        'original_filename' => $file->getClientOriginalName(),
                        'file_size_bytes' => $file->getSize(),
                        'mime_type' => $file->getMimeType(),
                        'notes' => 'Supplier-submitted invoice for bill '.$bill->bill_number,
                        'uploaded_by' => $portalUserId,
                        'uploaded_at' => now(),
                    ]);
                }

                return [
                    'bill' => $bill,
                'message' => 'Invoice submitted successfully. A draft bill is waiting for Accounts Payable review.',
                ];
            });

            if (str_starts_with((string) $result['message'], 'Invoice submitted successfully')) {
                event(new SupplierInvoiceSubmitted($result['bill']));
            }

            return $result;
        } catch (\Throwable $e) {
            if (is_string($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $e;
        }
    }

    /**
     * Find the default expense account hash_id for bill items.
     */
    private function defaultExpenseAccountHashId(): string
    {
        $account = Account::query()
            ->where('code', $this->settings->requiredString('accounting.default_expense_account_code'))
            ->first();

        if (! $account) {
            // Never infer an account from a display name: chart-of-accounts
            // labels are deployment data and may vary by tenant or locale.
            // The configured code is the authoritative mapping.
            throw new \App\Common\Exceptions\BusinessRuleException('Configured supplier-portal expense account was not found. Please contact the administrator.');
        }

        return $account->hash_id;
    }

    /* ─── Invoices / Bills ───────────────────────────────────────── */

    public function invoices(int $vendorId, array $filters): LengthAwarePaginator
    {
        $query = Bill::where('vendor_id', $vendorId)
            ->with(['purchaseOrder:id,po_number', 'vendor:id,name'])
            ->orderByDesc('created_at');

        $perPage = min((int) ($filters['per_page'] ?? 25), 100);

        return $query->paginate($perPage);
    }

    public function invoiceDetail(int $vendorId, Bill $invoice): Bill
    {
        abort_if($invoice->vendor_id !== $vendorId, 403, 'You do not have access to this invoice.');

        $invoice->load([
            'purchaseOrder:id,po_number,date,total_amount,status',
            'vendor:id,name',
            'items',
            'payments',
        ]);

        return $invoice;
    }

    /* ─── Deliveries / GRN ───────────────────────────────────────── */

    public function deliveries(int $vendorId, array $filters): Collection
    {
        $query = GoodsReceiptNote::where('vendor_id', $vendorId)
            ->with(['purchaseOrder:id,po_number'])
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->get();
    }

    /* ─── Statement of Account ───────────────────────────────────── */

    public function statementOfAccount(int $vendorId): array
    {
        $openBills = Bill::where('vendor_id', $vendorId)
            ->with('purchaseOrder:id,po_number')
            ->whereIn('status', [BillStatus::Unpaid->value, BillStatus::Partial->value])
            ->orderBy('due_date')
            ->get();

        $aging = ['current' => 0, 'd1_30' => 0, 'd31_60' => 0, 'd61_90' => 0, 'd91_plus' => 0];
        $totalOutstanding = 0;

        foreach ($openBills as $bill) {
            $bucket = $bill->agingBucket();
            $balance = (float) $bill->balance;
            if (isset($aging[$bucket])) {
                $aging[$bucket] += $balance;
            }
            $totalOutstanding += $balance;
        }

        $vendor = Vendor::find($vendorId);

        return [
            'vendor_name' => $vendor?->name,
            'total_outstanding' => number_format($totalOutstanding, 2),
            'aging_buckets' => [
                'current' => number_format($aging['current'], 2),
                'd1_30' => number_format($aging['d1_30'], 2),
                'd31_60' => number_format($aging['d31_60'], 2),
                'd61_90' => number_format($aging['d61_90'], 2),
                'd91_plus' => number_format($aging['d91_plus'], 2),
            ],
            'aging_bucket_options' => array_map(
                static fn (SupplierAgingBucket $bucket): array => ['value' => $bucket->value, 'label' => $bucket->label()],
                SupplierAgingBucket::cases(),
            ),
            'open_bills' => $openBills,
            'as_of_date' => now()->toDateString(),
        ];
    }

    /* ─── Delivery Schedules ─────────────────────────────────────── */

    public function deliverySchedules(int $vendorId): Collection
    {
        return DeliverySchedule::where('vendor_id', $vendorId)
            ->with('purchaseOrder:id,po_number')
            ->orderByDesc('month')
            ->orderByDesc('created_at')
            ->get();
    }

    public function storeDeliverySchedule(int $vendorId, array $data): DeliverySchedule
    {
        $decodedPoId = HashIdFilter::decode($data['purchase_order_id'], PurchaseOrder::class);
        $po = PurchaseOrder::query()
            ->whereKey($decodedPoId)
            ->where('vendor_id', $vendorId)
            ->firstOrFail();

        // Idempotent — a portal double-click or a retried request must not
        // stack a second schedule for the same PO + month. Lock the existing
        // submission first and return it, mirroring submitInvoice's
        // already-submitted path. The partial unique index
        // `delivery_schedules_vendor_po_month_unique` backs this guard at the
        // DB level.
        $schedule = DB::transaction(function () use ($vendorId, $po, $data): DeliverySchedule {
            $existing = DeliverySchedule::query()
                ->where('vendor_id', $vendorId)
                ->where('purchase_order_id', $po->id)
                ->where('month', $data['month'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            return DeliverySchedule::create([
                'vendor_id' => $vendorId,
                'purchase_order_id' => $po->id,
                'month' => $data['month'],
                'status' => 'submitted',
                'lines' => $data['lines'],
            ]);
        });

        return $schedule;
    }

    /* ─── PPAP Submissions ───────────────────────────────────────── */

    public function ppapSubmissions(int $vendorId, array $filters): LengthAwarePaginator
    {
        $query = PpapSubmission::query()
            ->where('vendor_id', $vendorId)
            ->with(['item:id,code,name', 'elements'])
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $perPage = min((int) ($filters['per_page'] ?? 25), 100);

        return $query->paginate($perPage);
    }
}
