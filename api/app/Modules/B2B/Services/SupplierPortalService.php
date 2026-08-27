<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\AuditLog;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Common\Services\SettingsService;
use App\Common\Services\TaxPolicyService;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\DeliverySchedule;
use App\Modules\B2B\Models\SupplierShipment;
use App\Modules\B2B\Models\SupplierShipmentUpdate;
use App\Modules\B2B\Events\SupplierInvoiceSubmitted;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\B2B\Enums\SupplierAgingBucket;
use App\Modules\B2B\Models\PortalShippingDocument;
use App\Common\Services\SystemUserResolver;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
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
    /** Supplier-visible history starts at approval and excludes internal drafts. */
    private const SUPPLIER_VISIBLE_PO_STATUSES = [
        PurchaseOrderStatus::Approved,
        PurchaseOrderStatus::Sent,
        PurchaseOrderStatus::PartiallyReceived,
        PurchaseOrderStatus::Received,
        PurchaseOrderStatus::Closed,
    ];

    /** Supplier actions are only available after the internal lifecycle gate. */
    private const SUPPLIER_SHIPMENT_STATUSES = [
        PurchaseOrderStatus::Sent,
        PurchaseOrderStatus::PartiallyReceived,
    ];

    private const SUPPLIER_DOCUMENT_STATUSES = [
        PurchaseOrderStatus::Sent,
        PurchaseOrderStatus::PartiallyReceived,
    ];

    private const SUPPLIER_INVOICE_STATUSES = [
        PurchaseOrderStatus::Sent,
        PurchaseOrderStatus::PartiallyReceived,
        PurchaseOrderStatus::Received,
    ];

    private const SUPPLIER_SCHEDULE_STATUSES = [
        PurchaseOrderStatus::Sent,
        PurchaseOrderStatus::PartiallyReceived,
    ];

    /** Draft/cancelled AP workflow rows are not supplier-facing invoices. */
    private const SUPPLIER_VISIBLE_BILL_STATUSES = [
        BillStatus::Unpaid,
        BillStatus::Partial,
        BillStatus::Paid,
    ];

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
        $visiblePoStatuses = $this->supplierVisiblePoStatusValues();
        $openPoCount = PurchaseOrder::where('vendor_id', $vendorId)
            ->whereIn('status', [PurchaseOrderStatus::Approved->value, PurchaseOrderStatus::Sent->value])->count();

        $pendingDeliveryCount = PurchaseOrder::where('vendor_id', $vendorId)
            ->where('status', PurchaseOrderStatus::Sent->value)->count();

        $unpaidInvoiceCount = Bill::where('vendor_id', $vendorId)
            ->whereIn('status', [BillStatus::Unpaid->value, BillStatus::Partial->value])->count();

        $totalUnpaid = Money::add(...Bill::where('vendor_id', $vendorId)
            ->whereIn('status', [BillStatus::Unpaid->value, BillStatus::Partial->value])
            ->pluck('balance')
            ->map(static fn (mixed $balance): string => (string) $balance)
            ->all());

        $recentPos = PurchaseOrder::where('vendor_id', $vendorId)
            ->whereIn('status', $visiblePoStatuses)
            ->with(['items.item:id,code,name,unit_of_measure'])
            ->orderByDesc('created_at')->limit(5)->get();

        $recentInvoices = Bill::where('vendor_id', $vendorId)
            ->whereIn('status', $this->supplierVisibleBillStatusValues())
            ->with('purchaseOrder:id,po_number')
            ->orderByDesc('created_at')->limit(5)->get();

        return [
            'open_po_count' => $openPoCount,
            'pending_delivery_count' => $pendingDeliveryCount,
            'unpaid_invoice_count' => $unpaidInvoiceCount,
            'total_unpaid_amount' => $totalUnpaid,
            'recent_pos' => $recentPos,
            'recent_invoices' => $recentInvoices,
        ];
    }

    /* ─── Purchase Orders ────────────────────────────────────────── */

    public function purchaseOrders(int $vendorId, array $filters): LengthAwarePaginator
    {
        $query = PurchaseOrder::where('vendor_id', $vendorId)
            ->with(['vendor:id,name', 'items.item:id,code,name,unit_of_measure'])
            ->withCount('goodsReceiptNotes')
            ->whereIn('status', $this->supplierVisiblePoStatusValues());

        if (! empty($filters['status'])) {
            $status = PurchaseOrderStatus::tryFrom((string) $filters['status']);
            if ($status === null || ! in_array($status, self::SUPPLIER_VISIBLE_PO_STATUSES, true)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('status', $status->value);
            }
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

        $perPage = max(1, min((int) ($filters['per_page'] ?? 25), 100));

        return $query->paginate($perPage);
    }

    public function purchaseOrderDetail(int $vendorId, PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        abort_if($purchaseOrder->vendor_id !== $vendorId, 403);
        abort_if(! in_array($purchaseOrder->status, self::SUPPLIER_VISIBLE_PO_STATUSES, true), 404);

        $purchaseOrder->load([
            'vendor:id,name,contact_person,email,phone,address',
            'items.item:id,code,name,unit_of_measure',
            'goodsReceiptNotes:id,grn_number,received_date,status',
            'bills:id,bill_number,total_amount,amount_paid,balance,status,due_date',
            'purchaseRequest:id,pr_number',
            'supplierShipment',
        ]);

        $purchaseOrder->bills->each(function ($bill): void {
            $bill->setAttribute(
                'status_label',
                BillStatus::tryFrom((string) $bill->status)?->label() ?? (string) $bill->status,
            );
        });

        return $purchaseOrder;
    }

    public function acknowledgePo(int $vendorId, int $portalUserId, PurchaseOrder $purchaseOrder, array $data): PurchaseOrder
    {
        abort_if($purchaseOrder->vendor_id !== $vendorId, 403);

        $result = $this->systemUser->impersonate(function () use ($purchaseOrder, $data) {
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

        $this->recordPortalAudit('supplier_po.ack', $result, $portalUserId, $vendorId);

        return $result;
    }

    public function updateShipment(int $vendorId, int $portalUserId, PurchaseOrder $purchaseOrder, array $data): PurchaseOrder
    {
        abort_if($purchaseOrder->vendor_id !== $vendorId, 403);

        $result = $this->systemUser->impersonate(function () use ($purchaseOrder, $data, $vendorId, $portalUserId) {
            return DB::transaction(function () use ($purchaseOrder, $data, $vendorId, $portalUserId): PurchaseOrder {
                // Shipment updates share the PO row with acknowledgement,
                // cancellation, and receiving. Re-read and lock the
                // authoritative row so a stale portal page cannot overwrite a
                // newer transition or append to an obsolete remarks value.
                $row = PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->id);
                abort_if((int) $row->vendor_id !== $vendorId, 403);

                if (! in_array($row->status, self::SUPPLIER_SHIPMENT_STATUSES, true)) {
                    throw new BusinessRuleException('Shipment updates are only allowed after the purchase order has been sent and before it is fully received or closed.');
                }

                $shipment = SupplierShipment::query()
                    ->where('purchase_order_id', $row->id)
                    ->lockForUpdate()
                    ->first();
                if (! $shipment) {
                    $shipment = new SupplierShipment(['purchase_order_id' => $row->id]);
                }

                $values = [
                    'shipped_date' => array_key_exists('shipped_date', $data)
                        ? $data['shipped_date']
                        : optional($shipment->shipped_date)->toDateString(),
                    'carrier' => array_key_exists('carrier', $data)
                        ? trim((string) $data['carrier'])
                        : $shipment->carrier,
                    'tracking_number' => array_key_exists('tracking_number', $data)
                        ? trim((string) $data['tracking_number'])
                        : $shipment->tracking_number,
                    'estimated_arrival' => array_key_exists('estimated_arrival', $data)
                        ? $data['estimated_arrival']
                        : optional($shipment->estimated_arrival)->toDateString(),
                    'notes' => array_key_exists('notes', $data)
                        ? trim((string) $data['notes'])
                        : $shipment->notes,
                    'portal_user_id' => $portalUserId,
                ];

                $shipment->forceFill($values)->save();
                SupplierShipmentUpdate::create([
                    'supplier_shipment_id' => $shipment->id,
                    'portal_user_id' => $portalUserId,
                    'payload' => $values,
                    'created_at' => now(),
                ]);

                if (array_key_exists('estimated_arrival', $data)) {
                    $row->expected_delivery_date = $data['estimated_arrival'];
                    $row->save();
                }

                return $row->fresh()->load('supplierShipment');
            });
        });

        $this->recordPortalAudit('supplier_ship.update', $result, $portalUserId, $vendorId);

        return $result;
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
        $contentHash = hash_file('sha256', (string) $file->getRealPath());
        if (! is_string($contentHash) || $contentHash === '') {
            throw new \RuntimeException('Unable to calculate the shipping document fingerprint.');
        }

        $path = $file->store($folder, 'local');
        if ($path === false) {
            // Storage fault. The supplier already passed validation and the
            // upload itself succeeded; there is nothing on their side to fix.
            throw new \RuntimeException('Unable to store the shipping document.');
        }

        try {
            // Idempotent — a double-click or retried request that re-uploads
            // the same bytes (same PO + type + content digest) must not stack
            // a second document row or orphan a second stored file. Lock the
            // PO row to serialize concurrent uploads, then return the existing
            // row and delete the just-stored duplicate. The content-digest
            // unique index backs this guard at the DB level.
            $document = DB::transaction(function () use ($vendorId, $purchaseOrder, $portalUserId, $path, $file, $data, $contentHash): PortalShippingDocument {
                $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->id);
                abort_if((int) $po->vendor_id !== $vendorId, 403);
                if (! in_array($po->status, self::SUPPLIER_DOCUMENT_STATUSES, true)) {
                    throw new BusinessRuleException('Shipping documents are only accepted for sent or partially received purchase orders.');
                }

                $existing = PortalShippingDocument::query()
                    ->where('purchase_order_id', $po->id)
                    ->where('document_type', $data['document_type'])
                    ->where('content_sha256', $contentHash)
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
                    'content_sha256' => $contentHash,
                    'mime_type' => $file->getMimeType(),
                    'notes' => $data['notes'] ?? null,
                    'uploaded_by' => $portalUserId,
                    'uploaded_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            // The cleanup window ends at COMMIT. Past that point a persisted
            // document row owns this path, and deleting the file would leave a
            // readable record pointing at nothing — worse than the orphan file
            // this guard exists to prevent. So only the transaction is wrapped;
            // a later load/audit failure must not take the stored file with it.
            Storage::disk('local')->delete($path);
            throw $e;
        }

        $document->load(['purchaseOrder', 'uploader']);
        $this->recordPortalAudit('supplier_doc.upload', $document, $portalUserId, $vendorId);

        return $document;
    }

    public function shippingDocuments(int $vendorId, PurchaseOrder $purchaseOrder): Collection
    {
        abort_if($purchaseOrder->vendor_id !== $vendorId, 403);

        return PortalShippingDocument::where('purchase_order_id', $purchaseOrder->id)
            ->with(['purchaseOrder', 'uploader'])
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

                if (! in_array($lockedPurchaseOrder->status, self::SUPPLIER_INVOICE_STATUSES, true)) {
                    throw new BusinessRuleException('Supplier invoices are only accepted for sent or receiving-stage purchase orders.');
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
                        // Storage fault, as above.
                        throw new \RuntimeException('Unable to store the supplier invoice.');
                    }

                    $contentHash = hash_file('sha256', (string) $file->getRealPath());
                    if (! is_string($contentHash) || $contentHash === '') {
                        throw new \RuntimeException('Unable to calculate the supplier invoice fingerprint.');
                    }

                    PortalShippingDocument::create([
                        'purchase_order_id' => $lockedPurchaseOrder->id,
                        'bill_id' => $bill->id,
                        'document_type' => 'supplier_invoice',
                        'file_path' => $storedPath,
                        'original_filename' => $file->getClientOriginalName(),
                        'file_size_bytes' => $file->getSize(),
                        'content_sha256' => $contentHash,
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
        } catch (\Throwable $e) {
            // The attachment is provisional only until the transaction commits.
            // After commit, the persisted document row owns the path; a later
            // event or audit failure must not leave that row pointing nowhere.
            if (is_string($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $e;
        }

        if (str_starts_with((string) $result['message'], 'Invoice submitted successfully')) {
            event(new SupplierInvoiceSubmitted($result['bill']));
            $this->recordPortalAudit('supplier_inv.submit', $result['bill'], $portalUserId, $vendorId);
        }

        return $result;
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
            ->whereIn('status', $this->supplierVisibleBillStatusValues())
            ->with(['purchaseOrder:id,po_number', 'vendor:id,name'])
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $status = BillStatus::tryFrom((string) $filters['status']);
            if ($status === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('status', $status->value);
            }
        }

        $perPage = max(1, min((int) ($filters['per_page'] ?? 25), 100));

        return $query->paginate($perPage);
    }

    public function invoiceDetail(int $vendorId, Bill $invoice): Bill
    {
        abort_if($invoice->vendor_id !== $vendorId, 403, 'You do not have access to this invoice.');
        abort_if(! in_array($invoice->status, self::SUPPLIER_VISIBLE_BILL_STATUSES, true), 404);

        $invoice->load([
            'purchaseOrder:id,po_number,date,total_amount,status',
            'vendor:id,name',
            'items',
            'payments',
        ]);

        return $invoice;
    }

    /* ─── Deliveries / GRN ───────────────────────────────────────── */

    public function deliveries(int $vendorId, array $filters): LengthAwarePaginator
    {
        $query = GoodsReceiptNote::where('vendor_id', $vendorId)
            ->with(['purchaseOrder:id,po_number'])
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $perPage = max(1, min((int) ($filters['per_page'] ?? 25), 100));

        return $query->paginate($perPage);
    }

    /* ─── Statement of Account ───────────────────────────────────── */

    public function statementOfAccount(int $vendorId): array
    {
        $openBills = Bill::where('vendor_id', $vendorId)
            ->with('purchaseOrder:id,po_number')
            ->whereIn('status', [BillStatus::Unpaid->value, BillStatus::Partial->value])
            ->orderBy('due_date')
            ->get();

        $aging = array_fill_keys(['current', 'd1_30', 'd31_60', 'd61_90', 'd91_plus'], Money::zero());
        $totalOutstanding = Money::zero();

        foreach ($openBills as $bill) {
            $bucket = $bill->agingBucket();
            $balance = (string) $bill->balance;
            if (isset($aging[$bucket])) {
                $aging[$bucket] = Money::add($aging[$bucket], $balance);
            }
            $totalOutstanding = Money::add($totalOutstanding, $balance);
        }

        $vendor = Vendor::find($vendorId);

        return [
            'vendor_name' => $vendor?->name,
            'total_outstanding' => $totalOutstanding,
            'aging_buckets' => [
                'current' => $aging['current'],
                'd1_30' => $aging['d1_30'],
                'd31_60' => $aging['d31_60'],
                'd61_90' => $aging['d61_90'],
                'd91_plus' => $aging['d91_plus'],
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

    public function deliverySchedules(int $vendorId, array $filters = []): LengthAwarePaginator
    {
        return DeliverySchedule::where('vendor_id', $vendorId)
            ->with([
                'purchaseOrder:id,po_number',
                'purchaseOrder.items:id,purchase_order_id,description',
            ])
            ->orderByDesc('month')
            ->orderByDesc('created_at')
            ->paginate(max(1, min((int) ($filters['per_page'] ?? 25), 100)));
    }

    public function storeDeliverySchedule(int $vendorId, int $portalUserId, array $data): DeliverySchedule
    {
        $decodedPoId = HashIdFilter::decode($data['purchase_order_id'], PurchaseOrder::class);

        $schedule = DB::transaction(function () use ($vendorId, $portalUserId, $decodedPoId, $data): DeliverySchedule {
            $po = PurchaseOrder::query()
                ->whereKey($decodedPoId)
                ->where('vendor_id', $vendorId)
                ->lockForUpdate()
                ->firstOrFail();
            if (! in_array($po->status, self::SUPPLIER_SCHEDULE_STATUSES, true)) {
                throw new BusinessRuleException('Delivery schedules are only accepted for sent or partially received purchase orders.');
            }

            $normalizedLines = $this->normalizeScheduleLines($po, $data['lines']);
            $existing = DeliverySchedule::query()
                ->where('vendor_id', $vendorId)
                ->where('purchase_order_id', $po->id)
                ->where('month', $data['month'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->lines !== $normalizedLines) {
                    throw new BusinessRuleException('A delivery schedule already exists for this purchase order and month with a different payload.');
                }

                return $existing->load([
                    'purchaseOrder:id,po_number',
                    'purchaseOrder.items:id,purchase_order_id,description',
                ]);
            }

            $schedule = DeliverySchedule::create([
                'vendor_id' => $vendorId,
                'purchase_order_id' => $po->id,
                'month' => $data['month'],
                'status' => 'submitted',
                'lines' => $normalizedLines,
            ]);

            $this->recordPortalAudit('supplier_sched.sub', $schedule, $portalUserId, $vendorId);

            return $schedule->load([
                'purchaseOrder:id,po_number',
                'purchaseOrder.items:id,purchase_order_id,description',
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

        $perPage = max(1, min((int) ($filters['per_page'] ?? 25), 100));

        return $query->paginate($perPage);
    }

    /** @return array<int, string> */
    private function supplierVisiblePoStatusValues(): array
    {
        return array_map(static fn (PurchaseOrderStatus $status): string => $status->value, self::SUPPLIER_VISIBLE_PO_STATUSES);
    }

    /** @return array<int, string> */
    private function supplierVisibleBillStatusValues(): array
    {
        return array_map(static fn (BillStatus $status): string => $status->value, self::SUPPLIER_VISIBLE_BILL_STATUSES);
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function normalizeScheduleLines(PurchaseOrder $purchaseOrder, array $lines): array
    {
        $items = $purchaseOrder->items()->lockForUpdate()->get()->keyBy('id');
        $seen = [];
        $normalized = [];

        foreach ($lines as $line) {
            $itemId = HashIdFilter::decode((string) ($line['purchase_order_item_id'] ?? ''), PurchaseOrderItem::class);
            $item = $itemId === null ? null : $items->get($itemId);
            if (! $item || isset($seen[$item->id])) {
                throw new BusinessRuleException('Each delivery schedule line must identify a unique item on the purchase order.');
            }

            $quantity = (string) $line['quantity'];
            $remaining = Money::sub((string) $item->quantity, (string) $item->quantity_received);
            if (Money::lte($quantity, '0') || Money::gt($quantity, $remaining)) {
                throw new BusinessRuleException("Scheduled quantity for {$item->description} exceeds the remaining purchase-order quantity.");
            }

            $seen[$item->id] = true;
            $normalized[] = [
                'purchase_order_item_id' => $item->hash_id,
                'product_name' => $item->item?->name ?? $item->description,
                'quantity' => Money::round2($quantity),
                'notes' => $line['notes'] ?? null,
            ];
        }

        return $normalized;
    }

    private function recordPortalAudit(string $action, object $model, int $portalUserId, int $vendorId): void
    {
        AuditLog::create([
            'user_id' => null,
            'actor_type' => 'supplier_portal',
            'action' => $action,
            'model_type' => $model::class,
            'model_id' => $model->getKey(),
            'old_values' => null,
            'new_values' => [
                'portal_user_id' => $portalUserId,
                'vendor_id' => $vendorId,
            ],
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'source_command' => request()?->route()?->getName() ?? 'supplier_portal',
            'correlation_id' => request()?->attributes->get('request_id') ?? request()?->header('X-Request-ID'),
            'created_at' => now(),
        ]);
    }
}
