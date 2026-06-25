<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Common\Support\HashIdFilter;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\DeliverySchedule;
use App\Modules\B2B\Models\PortalShippingDocument;
use App\Modules\Edge\Services\EdgeSystemUserResolver;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Resources\PurchaseOrderResource;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
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
        private readonly EdgeSystemUserResolver $systemUser,
    ) {}

    /* ─── Dashboard ──────────────────────────────────────────────── */

    public function dashboard(int $vendorId): array
    {
        $openPoCount = PurchaseOrder::where('vendor_id', $vendorId)
            ->whereIn('status', ['approved', 'sent'])->count();

        $pendingDeliveryCount = PurchaseOrder::where('vendor_id', $vendorId)
            ->where('status', 'sent')->count();

        $unpaidInvoiceCount = Bill::where('vendor_id', $vendorId)
            ->whereIn('status', ['unpaid', 'partial'])->count();

        $totalUnpaid = Bill::where('vendor_id', $vendorId)
            ->whereIn('status', ['unpaid', 'partial'])->sum('balance');

        $recentPos = PurchaseOrder::where('vendor_id', $vendorId)
            ->orderByDesc('created_at')->limit(5)->get();

        $recentInvoices = Bill::where('vendor_id', $vendorId)
            ->with('purchaseOrder:id,po_number')
            ->orderByDesc('created_at')->limit(5)->get();

        return [
            'open_po_count'          => $openPoCount,
            'pending_delivery_count' => $pendingDeliveryCount,
            'unpaid_invoice_count'   => $unpaidInvoiceCount,
            'total_unpaid_amount'    => number_format((float) $totalUnpaid, 2),
            'recent_pos'             => $recentPos,
            'recent_invoices'        => $recentInvoices,
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
        $sortDir   = $filters['dir'] ?? 'desc';
        $allowed   = ['po_number', 'date', 'total_amount', 'status', 'created_at'];
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

        return $purchaseOrder;
    }

    public function acknowledgePo(int $vendorId, PurchaseOrder $purchaseOrder, array $data): PurchaseOrder
    {
        abort_if($purchaseOrder->vendor_id !== $vendorId, 403);

        return $this->systemUser->impersonate(function () use ($purchaseOrder, $data) {
            $purchaseOrder->expected_delivery_date = $data['expected_delivery_date'] ?? $purchaseOrder->expected_delivery_date;
            $purchaseOrder->remarks                = $data['notes'] ?? $purchaseOrder->remarks;
            $purchaseOrder->status                 = 'sent';
            $purchaseOrder->sent_to_supplier_at    = now();
            $purchaseOrder->save();

            return $purchaseOrder;
        });
    }

    public function updateShipment(int $vendorId, PurchaseOrder $purchaseOrder, array $data): PurchaseOrder
    {
        abort_if($purchaseOrder->vendor_id !== $vendorId, 403);

        return $this->systemUser->impersonate(function () use ($purchaseOrder, $data) {
            $estimatedArrival = $data['estimated_arrival'] ?? $purchaseOrder->expected_delivery_date;
            $carrier   = $data['carrier'] ?? 'N/A';
            $tracking  = $data['tracking_number'] ?? 'N/A';
            $prevNotes = $purchaseOrder->remarks ? $purchaseOrder->remarks . "\n" : '';

            $purchaseOrder->expected_delivery_date = $estimatedArrival;
            $purchaseOrder->remarks                = $prevNotes . "Shipment: {$carrier} / {$tracking}";
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

        return PortalShippingDocument::create([
            'purchase_order_id' => $purchaseOrder->id,
            'document_type'     => $data['document_type'],
            'file_path'         => $path,
            'original_filename' => $file->getClientOriginalName(),
            'file_size_bytes'   => $file->getSize(),
            'mime_type'         => $file->getMimeType(),
            'notes'             => $data['notes'] ?? null,
            'uploaded_by'       => $portalUserId,
            'uploaded_at'       => now(),
        ]);
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
            'description'        => $poItem->description,
            'quantity'           => (string) $poItem->quantity,
            'unit'               => $poItem->unit ?? 'pcs',
            'unit_price'         => (string) $poItem->unit_price,
        ])->toArray();

        if (empty($items)) {
            throw new \RuntimeException('This purchase order has no items to bill.');
        }

        $internalUser = User::first();

        if (! $internalUser) {
            throw new \RuntimeException('No internal user available to create the bill.');
        }

        $bill = $this->bills->create([
            'bill_number'       => $data['bill_number'],
            'vendor_id'         => $purchaseOrder->vendor->hash_id,
            'purchase_order_id' => $purchaseOrder->hash_id,
            'date'              => $data['date'],
            'due_date'          => $data['due_date'] ?? $data['date'],
            'is_vatable'        => $data['is_vatable'] ?? true,
            'remarks'           => $data['remarks'] ?? null,
            'items'             => $items,
        ], $internalUser);

        if ($file) {
            $folder = "portal/supplier-invoices/{$bill->id}";
            $path = $file->store($folder, 'local');

            PortalShippingDocument::create([
                'purchase_order_id' => $purchaseOrder->id,
                'bill_id'           => $bill->id,
                'document_type'     => 'supplier_invoice',
                'file_path'         => $path,
                'original_filename' => $file->getClientOriginalName(),
                'file_size_bytes'   => $file->getSize(),
                'mime_type'         => $file->getMimeType(),
                'notes'             => 'Supplier-submitted invoice for bill ' . $bill->bill_number,
                'uploaded_by'       => $portalUserId,
                'uploaded_at'       => now(),
            ]);
        }

        return [
            'bill'    => $bill,
            'message' => 'Invoice submitted successfully. Bill has been created in Accounts Payable.',
        ];
    }

    /**
     * Find the default expense account hash_id for bill items.
     */
    private function defaultExpenseAccountHashId(): string
    {
        $account = Account::query()
            ->where('code', '5000')
            ->orWhere('name', 'like', '%Cost of Goods Sold%')
            ->first();

        if (! $account) {
            throw new \RuntimeException('No COGS/expense account configured. Please contact the administrator.');
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
            ->whereIn('status', ['unpaid', 'partial'])
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
            'vendor_name'       => $vendor?->name,
            'total_outstanding' => number_format($totalOutstanding, 2),
            'aging_buckets'     => [
                'current'  => number_format($aging['current'], 2),
                'd1_30'    => number_format($aging['d1_30'], 2),
                'd31_60'   => number_format($aging['d31_60'], 2),
                'd61_90'   => number_format($aging['d61_90'], 2),
                'd91_plus' => number_format($aging['d91_plus'], 2),
            ],
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
        $po = PurchaseOrder::findOrFail($decodedPoId);

        return DeliverySchedule::create([
            'vendor_id'         => $vendorId,
            'purchase_order_id' => $po->id,
            'month'             => $data['month'],
            'status'            => 'submitted',
            'lines'             => $data['lines'],
        ]);
    }

    /* ─── PPAP Submissions ───────────────────────────────────────── */

    public function ppapSubmissions(int $vendorId, array $filters): LengthAwarePaginator
    {
        $query = \App\Modules\Quality\Models\PpapSubmission::query()
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
