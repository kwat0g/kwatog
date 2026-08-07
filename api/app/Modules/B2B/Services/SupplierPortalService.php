<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

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
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\B2B\Enums\SupplierAgingBucket;
use App\Modules\B2B\Models\PortalShippingDocument;
use App\Common\Services\SystemUserResolver;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Purchasing\Models\PurchaseOrder;
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
            $purchaseOrder->expected_delivery_date = $data['expected_delivery_date'] ?? $purchaseOrder->expected_delivery_date;
            $purchaseOrder->remarks = $data['notes'] ?? $purchaseOrder->remarks;
            $purchaseOrder->status = PurchaseOrderStatus::Sent->value;
            $purchaseOrder->sent_to_supplier_at = now();
            $purchaseOrder->save();

            return $purchaseOrder;
        });
    }

    public function updateShipment(int $vendorId, PurchaseOrder $purchaseOrder, array $data): PurchaseOrder
    {
        abort_if($purchaseOrder->vendor_id !== $vendorId, 403);

        return $this->systemUser->impersonate(function () use ($purchaseOrder, $data) {
            $estimatedArrival = $data['estimated_arrival'] ?? $purchaseOrder->expected_delivery_date;
            $carrier = $data['carrier'] ?? null;
            $tracking = $data['tracking_number'] ?? null;
            $prevNotes = $purchaseOrder->remarks ? $purchaseOrder->remarks."\n" : '';

            $purchaseOrder->expected_delivery_date = $estimatedArrival;
            $shipment = implode(' / ', array_filter([$carrier, $tracking], static fn ($v) => $v !== null && $v !== ''));
            $purchaseOrder->remarks = $shipment !== '' ? $prevNotes."Shipment: {$shipment}" : $prevNotes;
            $purchaseOrder->save();

            return $purchaseOrder;
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
            return PortalShippingDocument::create([
                'purchase_order_id' => $purchaseOrder->id,
                'document_type' => $data['document_type'],
                'file_path' => $path,
                'original_filename' => $file->getClientOriginalName(),
                'file_size_bytes' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'notes' => $data['notes'] ?? null,
                'uploaded_by' => $portalUserId,
                'uploaded_at' => now(),
            ]);
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

        $purchaseOrder->load(['vendor:id,name', 'items.item']);

        $defaultAccountHashId = $this->defaultExpenseAccountHashId();

        $items = $purchaseOrder->items->map(fn ($poItem) => [
            'expense_account_id' => $defaultAccountHashId,
            'description' => $poItem->description,
            'quantity' => (string) $poItem->quantity,
            'unit' => $poItem->unit,
            'unit_price' => (string) $poItem->unit_price,
        ])->toArray();

        if (empty($items)) {
            // A supplier hitting this saw a generic 500 "Server Error" page with
            // no clue what to fix. It is a state violation, not a server fault.
            throw new \App\Common\Exceptions\BusinessRuleException('This purchase order has no items to bill.');
        }

        $storedPath = null;
        try {
            return DB::transaction(function () use ($purchaseOrder, $data, $items, $file, $portalUserId, &$storedPath) {
                $systemUser = app(SystemUserResolver::class);

                $bill = $systemUser->impersonate(fn () => $this->bills->create([
                    'bill_number' => $data['bill_number'],
                    'vendor_id' => $purchaseOrder->vendor->hash_id,
                    'purchase_order_id' => $purchaseOrder->hash_id,
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
                        'purchase_order_id' => $purchaseOrder->id,
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
                    'message' => 'Invoice submitted successfully. Bill has been created in Accounts Payable.',
                ];
            });
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
            ->orWhere('name', 'like', '%Cost of Goods Sold%')
            ->first();

        if (! $account) {
            // The message is already written for a human ("contact the
            // administrator"), so render it as a 422 the portal can display
            // instead of burying it in a 500.
            throw new \App\Common\Exceptions\BusinessRuleException('No COGS/expense account configured. Please contact the administrator.');
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

        return DeliverySchedule::create([
            'vendor_id' => $vendorId,
            'purchase_order_id' => $po->id,
            'month' => $data['month'],
            'status' => 'submitted',
            'lines' => $data['lines'],
        ]);
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
