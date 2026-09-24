<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Common\Services\SystemUserResolver;
use App\Common\Support\Money;
use App\Common\Support\SearchOperator;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\B2B\Enums\SupplierAgingBucket;
use App\Modules\B2B\Policies\SupplierPoCapabilities;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderResponse;
use App\Modules\Purchasing\Models\RequestForQuoteInvitation;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Purchasing\Services\SupplierResponseService;
use App\Modules\Quality\Enums\PpapStatus;
use App\Modules\Quality\Models\PpapSubmission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

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
    /**
     * Supplier-visible POs start at `sent` — the transmission boundary. An
     * approved-but-unsent PO is internal; exposing it let the supplier
     * "acknowledge" (and thereby mark sent) a PO OGAMI had not sent.
     */
    private const SUPPLIER_VISIBLE_PO_STATUSES = [
        PurchaseOrderStatus::Sent,
        PurchaseOrderStatus::Acknowledged,
        PurchaseOrderStatus::SupplierProposed,
        PurchaseOrderStatus::SupplierDeclined,
        PurchaseOrderStatus::PartiallyReceived,
        PurchaseOrderStatus::Received,
        PurchaseOrderStatus::Closed,
    ];

    /** Po numbers the supplier still expects to deliver (not yet received). */
    private const SUPPLIER_PENDING_DELIVERY_STATUSES = [
        PurchaseOrderStatus::Sent,
        PurchaseOrderStatus::Acknowledged,
        PurchaseOrderStatus::SupplierProposed,
        PurchaseOrderStatus::PartiallyReceived,
    ];

    /** Draft/cancelled AP workflow rows are not supplier-facing invoices. */
    private const SUPPLIER_VISIBLE_BILL_STATUSES = [
        BillStatus::Unpaid,
        BillStatus::Partial,
        BillStatus::Paid,
    ];

    public function __construct(
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly SupplierResponseService $supplierResponses,
        private readonly SystemUserResolver $systemUser,
        private readonly SupplierPortalAuditRecorder $audit,
    ) {}

    /* ─── Dashboard ──────────────────────────────────────────────── */

    public function dashboard(int $vendorId): array
    {
        // POs needing supplier attention: sent, acknowledged, supplier_proposed, supplier_declined
        // (pending), or partially_received. Excluded: declined with accepted response (closed by
        // purchasing), received, closed, cancelled.
        $openPoCount = PurchaseOrder::where('vendor_id', $vendorId)
            ->where(function ($q): void {
                $q->whereIn('status', $this->statusValues([
                    PurchaseOrderStatus::Sent,
                    PurchaseOrderStatus::Acknowledged,
                    PurchaseOrderStatus::SupplierProposed,
                    PurchaseOrderStatus::PartiallyReceived,
                ]))
                // Include supplier_declined only if the latest response is still pending
                ->orWhere(function ($q): void {
                    $q->where('status', PurchaseOrderStatus::SupplierDeclined->value)
                        ->whereHas('latestResponse', fn ($r) => $r->where('status', 'pending'));
                });
            })->count();

        // POs with at least one line quantity > quantity_received (still need to be fulfilled)
        $pendingDeliveryCount = PurchaseOrder::where('vendor_id', $vendorId)
            ->whereIn('status', $this->statusValues(SupplierPoCapabilities::FULFILMENT_STATUSES))
            ->whereHas('items', fn ($query) => $query->whereColumn('quantity', '>', 'quantity_received'))
            ->count();

        $unpaidInvoiceCount = Bill::where('vendor_id', $vendorId)
            ->whereIn('status', [BillStatus::Unpaid->value, BillStatus::Partial->value])->count();

        $totalUnpaid = Money::add(...Bill::where('vendor_id', $vendorId)
            ->whereIn('status', [BillStatus::Unpaid->value, BillStatus::Partial->value])
            ->pluck('balance')
            ->map(static fn (mixed $balance): string => (string) $balance)
            ->all());

        $recentPos = PurchaseOrder::where('vendor_id', $vendorId)
            ->where(fn ($query) => $this->whereSupplierVisible($query))
            ->with(['items.item:id,code,name,unit_of_measure', 'latestResponse.items'])
            ->withExists(['goodsReceiptNotes as has_invoiceable_receipt' => fn ($q) => SupplierPoCapabilities::constrainInvoiceable($q)])
            ->orderByDesc('created_at')->limit(5)->get();

        $recentInvoices = Bill::where('vendor_id', $vendorId)
            ->whereIn('status', $this->supplierVisibleBillStatusValues())
            ->with('purchaseOrder:id,po_number')
            ->orderByDesc('created_at')->limit(5)->get();

        // RFQs still waiting for this supplier's quotation.
        $openRfqCount = RequestForQuoteInvitation::query()
            ->where('vendor_id', $vendorId)
            ->whereIn('status', ['invited', 'viewed'])
            ->whereHas('rfq', fn ($q) => $q->where('status', 'open')->where('closes_at', '>', now()))
            ->count();

        return [
            'open_po_count' => $openPoCount,
            'open_rfq_count' => $openRfqCount,
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
            ->with(['vendor:id,name', 'items.item:id,code,name,unit_of_measure', 'latestResponse.items'])
            ->withCount('goodsReceiptNotes')
            ->withExists(['goodsReceiptNotes as has_invoiceable_receipt' => fn ($q) => SupplierPoCapabilities::constrainInvoiceable($q)])
            ->where(fn ($query) => $this->whereSupplierVisible($query));

        if (! empty($filters['status'])) {
            $status = PurchaseOrderStatus::tryFrom((string) $filters['status']);
            if ($status === null || ! $this->isSupplierVisibleStatus($status)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('status', $status->value);
            }
        }
        if (! empty($filters['search'])) {
            $query->where('po_number', SearchOperator::like(), SearchOperator::contains($filters['search']));
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
        $visible = in_array($purchaseOrder->status, self::SUPPLIER_VISIBLE_PO_STATUSES, true)
            || ($purchaseOrder->status === PurchaseOrderStatus::Cancelled && $purchaseOrder->sent_to_supplier_at !== null);
        abort_if(! $visible, 404);

        $purchaseOrder->load([
            'vendor:id,name,contact_person,email,phone,address',
            'items.item:id,code,name,unit_of_measure',
            'latestResponse.items',
            // HasMany eager loads are matched to their parent by the foreign
            // key, so `purchase_order_id` MUST be in the select list. These two
            // were written as `'bills:id,bill_number,…'` without it, and
            // HasMany::match() then found no dictionary key for any row and
            // discarded every one — so the supplier PO detail response always
            // carried `bills: []` and `goods_receipt_notes: []` no matter what
            // existed, and the SPA's two panels (which render only when the
            // array is non-empty) had never once displayed.
            'goodsReceiptNotes' => static fn ($query) => $query
                ->select(['id', 'purchase_order_id', 'grn_number', 'received_date', 'status', 'rejected_reason'])
                ->with(['bills' => fn ($q) => $q->select(['id', 'goods_receipt_note_id', 'status', 'supplier_invoice_number'])])
                ->orderBy('id'),
            // Restoring the rows above re-arms a boundary that was previously
            // masked by the same bug: `bills` had no status predicate, so the
            // internal draft/cancelled AP workflow rows the /invoices endpoint
            // deliberately hides would have crossed here instead. Both endpoints
            // now read the one allowlist.
            'bills' => fn ($query) => $query
                ->select(['id', 'purchase_order_id', 'bill_number', 'total_amount', 'amount_paid', 'balance', 'status', 'due_date', 'supplier_invoice_number'])
                ->whereIn('status', $this->supplierVisibleBillStatusValues())
                ->orderBy('id'),
            'purchaseRequest:id,pr_number',
            'supplierShipment',
            'supplierShipments',
        ]);

        // The `status_label` this used to setAttribute() here is already derived
        // by SupplierPurchaseOrderResource from the BillStatus enum, and the
        // resource builds an explicit array, so the attribute never reached the
        // response. Worse, `Bill::$casts` maps `status` to BillStatus, so its
        // `(string) $bill->status` was a fatal `Error: Object of class
        // BillStatus could not be converted to string` — invisible only because
        // the `bills` relation above always resolved empty. Deleted rather than
        // repaired: the one live derivation belongs in the resource.

        return $purchaseOrder;
    }

    public function acknowledgePo(int $vendorId, int $portalUserId, PurchaseOrder $purchaseOrder, array $data): PurchaseOrderResponse
    {
        abort_if($purchaseOrder->vendor_id !== $vendorId, 403);

        // Acknowledge is an accept-as-ordered: delegate to respond() so there's one
        // rule governing acceptance, whether via the acknowledge endpoint or the
        // generic respond endpoint.
        $result = $this->systemUser->impersonate(fn (): PurchaseOrderResponse => $this->supplierResponses->respond(
            $purchaseOrder,
            $vendorId,
            $portalUserId,
            [
                'type' => 'accept',
                'proposed_delivery_date' => $data['expected_delivery_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ],
        ));

        // Anchored on the PO, where reviewers look for the supplier's actions.
        $this->audit->record('supplier_po.ack', $purchaseOrder, $portalUserId, $vendorId);

        return $result;
    }

    /**
     * Supplier replies to a PO: accept, propose a counter-offer, or decline.
     *
     * Delegates to SupplierResponseService (single lifecycle owner); the
     * system-user impersonation keeps HasAuditLog's auth context on a real
     * `users` row while the portal guard is active.
     */
    public function respondToPo(
        int $vendorId,
        int $portalUserId,
        PurchaseOrder $purchaseOrder,
        array $data,
    ): PurchaseOrderResponse {
        abort_if($purchaseOrder->vendor_id !== $vendorId, 403);

        $result = $this->systemUser->impersonate(fn (): PurchaseOrderResponse => $this->supplierResponses->respond(
            $purchaseOrder,
            $vendorId,
            $portalUserId,
            $data,
        ));

        $this->audit->record('supplier_po.respond', $result, $portalUserId, $vendorId);

        return $result->load('items');
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
            'goodsReceiptNote:id,grn_number',
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
            ->with(['purchaseOrder:id,po_number', 'items.item:id,code,name'])
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $status = GrnStatus::tryFrom((string) $filters['status']);
            if ($status === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('status', $status->value);
            }
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

    /* ─── PPAP Submissions ───────────────────────────────────────── */

    public function ppapSubmissions(int $vendorId, array $filters): LengthAwarePaginator
    {
        $query = PpapSubmission::query()
            ->where('vendor_id', $vendorId)
            ->with(['item:id,code,name', 'elements'])
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $status = PpapStatus::tryFrom((string) $filters['status']);
            if ($status === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('status', $status->value);
            }
        }

        $perPage = max(1, min((int) ($filters['per_page'] ?? 25), 100));

        return $query->paginate($perPage);
    }

    /** @return array<int, string> */
    private function supplierVisiblePoStatusValues(): array
    {
        return $this->statusValues(self::SUPPLIER_VISIBLE_PO_STATUSES);
    }

    /**
     * A cancelled PO stays visible, read-only, once it was sent: the supplier
     * may already have planned production against it and must see that it is
     * off. A PO cancelled before sending was never theirs to see.
     */
    private function whereSupplierVisible(Builder $query): void
    {
        $query->whereIn('status', $this->supplierVisiblePoStatusValues())
            ->orWhere(fn (Builder $cancelled) => $cancelled
                ->where('status', PurchaseOrderStatus::Cancelled->value)
                ->whereNotNull('sent_to_supplier_at'));
    }

    private function isSupplierVisibleStatus(PurchaseOrderStatus $status): bool
    {
        return $status === PurchaseOrderStatus::Cancelled
            || in_array($status, self::SUPPLIER_VISIBLE_PO_STATUSES, true);
    }

    /**
     * @param  array<int, PurchaseOrderStatus>  $statuses
     * @return array<int, string>
     */
    private function statusValues(array $statuses): array
    {
        return array_map(static fn (PurchaseOrderStatus $status): string => $status->value, $statuses);
    }

    /** @return array<int, string> */
    private function supplierVisibleBillStatusValues(): array
    {
        return array_map(static fn (BillStatus $status): string => $status->value, self::SUPPLIER_VISIBLE_BILL_STATUSES);
    }
}
