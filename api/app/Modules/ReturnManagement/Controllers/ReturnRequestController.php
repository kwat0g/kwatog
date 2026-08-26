<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Controllers;

use App\Common\Services\SettingsService;
use App\Common\Support\HashId;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use App\Modules\ReturnManagement\Enums\DispositionType;
use App\Common\Support\HashIdFilter;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Models\Customer;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\ReturnManagement\Requests\CompleteReturnRequest;
use App\Modules\ReturnManagement\Requests\DisposeReturnRequest;
use App\Modules\ReturnManagement\Requests\ReceiveReturnRequest;
use App\Modules\ReturnManagement\Requests\StoreReturnRequestRequest;
use App\Modules\ReturnManagement\Requests\UpdateReturnRequestRequest;
use App\Modules\ReturnManagement\Resources\ReturnRequestResource;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class ReturnRequestController extends Controller
{
    public function __construct(
        private readonly ReturnRequestService $service,
        private readonly SettingsService $settings,
    ) {}

    public function options(Request $request): JsonResponse
    {
        $read = fn (string $key): array => array_values(array_filter(
            (array) $this->settings->get($key, []),
            static fn ($option): bool => is_array($option) && isset($option['value'], $option['label']),
        ));

        $matrix = [
            ReturnRequestType::CustomerReturn->value => [
                'regular' => array_map(
                    static fn (DispositionType $disposition): array => ['value' => $disposition->value, 'label' => $disposition->label()],
                    DispositionType::allowedFor(ReturnRequestType::CustomerReturn),
                ),
                'finance_only' => array_map(
                    static fn (DispositionType $disposition): array => ['value' => $disposition->value, 'label' => $disposition->label()],
                    DispositionType::allowedFor(ReturnRequestType::CustomerReturn, true),
                ),
            ],
            ReturnRequestType::SupplierReturn->value => [
                'regular' => array_map(
                    static fn (DispositionType $disposition): array => ['value' => $disposition->value, 'label' => $disposition->label()],
                    DispositionType::allowedFor(ReturnRequestType::SupplierReturn),
                ),
            ],
        ];

        $requestedType = (string) $request->query('type', '');
        $requestedFinanceOnly = filter_var($request->query('finance_only', false), FILTER_VALIDATE_BOOLEAN);
        $requestedDispositions = $matrix[$requestedType] ?? null;
        $dispositions = $requestedDispositions
            ? ($requestedDispositions[$requestedFinanceOnly ? 'finance_only' : 'regular'] ?? $requestedDispositions['regular'])
            : array_map(
                static fn (DispositionType $disposition): array => ['value' => $disposition->value, 'label' => $disposition->label()],
                DispositionType::cases(),
            );

        return response()->json(['data' => [
            'types' => array_map(
                static fn (ReturnRequestType $type): array => ['value' => $type->value, 'label' => $type->label()],
                ReturnRequestType::cases(),
            ),
            'statuses' => array_map(
                static fn (ReturnRequestStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
                ReturnRequestStatus::cases(),
            ),
            'reasons' => $read('returns.reason_codes'),
            'resolutions' => $read('returns.resolutions'),
            'conditions' => $read('returns.item_conditions'),
            'dispositions' => $dispositions,
            'disposition_matrix' => $matrix,
        ]]);
    }

    /**
     * Active reservations for every line of the given documents, keyed by raw
     * line id. One query per source kind (see
     * `ReturnRequestService::activeAllocationsBySource()`), never per line.
     *
     * @param  iterable<object> $documents
     * @return array<int, string>
     */
    private function reservedFor(string $sourceKind, iterable $documents): array
    {
        $ids = [];
        foreach ($documents as $document) {
            foreach ($document->items as $line) {
                $ids[] = (int) $line->id;
            }
        }

        return $this->service->activeAllocationsBySource($sourceKind, array_values(array_unique($ids)));
    }

    /**
     * RMA-010 — what the operator can actually still return on a source line:
     * the document quantity minus every active RMA reservation against it.
     * Advertising the raw document quantity meant two operators saw the same
     * headroom, one hit a late `reserveSource()` rejection at submit, and
     * neither could see the reservation responsible. Clamped at zero so a
     * fully-reserved line reads 0.000 rather than a negative.
     *
     * Reported at 3 dp — the precision of `return_request_source_allocations`
     * and of an RMA line — while the sibling `quantity` keeps its own source
     * document's cast (invoice lines are decimal:2, GRN lines decimal:3). The
     * two fields can therefore differ in trailing zeros on the same line; that
     * is deliberate, not drift.
     *
     * @param array<int, string> $reserved
     */
    private function remainingOnLine(string $documentQuantity, array $reserved, int $lineId): string
    {
        $left = bcsub(bcadd($documentQuantity, '0', 3), $reserved[$lineId] ?? '0', 3);

        return bccomp($left, '0', 3) > 0 ? $left : '0.000';
    }

    /**
     * Return party-scoped source documents and line identities for RMA entry.
     * The SPA never needs raw integer IDs; every document and line is returned
     * as a HashID and the service remains the final authority on provenance.
     */
    public function sourceOptions(Request $request): JsonResponse
    {
        $type = (string) $request->query('type');

        if ($type === ReturnRequestType::CustomerReturn->value) {
            $customerId = HashIdFilter::decode($request->query('customer_id'), Customer::class);
            if (! $customerId) {
                return response()->json(['message' => 'Select a customer to load return source documents.'], 422);
            }

            $invoiceModels = Invoice::query()
                ->where('customer_id', $customerId)
                ->whereNotIn('status', ['draft', 'cancelled'])
                ->with('items')
                ->latest('date')
                ->limit(100)
                ->get();
            $invoiceReserved = $this->reservedFor('invoice_item', $invoiceModels);
            $invoices = $invoiceModels
                ->map(fn (Invoice $invoice): array => [
                    'id' => $invoice->hash_id,
                    'label' => $invoice->invoice_number,
                    'sales_order_id' => $invoice->sales_order_id ? HashId::encode((int) $invoice->sales_order_id) : null,
                    'lines' => $invoice->items->map(fn ($line): array => [
                        'id' => $line->hash_id,
                        'product_id' => $line->product_id ? HashId::encode((int) $line->product_id) : null,
                        'quantity' => (string) $line->quantity,
                        'remaining_quantity' => $this->remainingOnLine((string) $line->quantity, $invoiceReserved, (int) $line->id),
                        'unit_price' => (string) $line->unit_price,
                        'label' => (string) ($line->description ?: 'Invoice line '.$line->id),
                    ])->values(),
                ])->values();

            $salesOrderModels = SalesOrder::query()
                ->where('customer_id', $customerId)
                ->where('status', '<>', 'cancelled')
                ->with('items')
                ->latest('date')
                ->limit(100)
                ->get();
            $salesOrderReserved = $this->reservedFor('sales_order_item', $salesOrderModels);
            $salesOrders = $salesOrderModels
                ->map(fn (SalesOrder $order): array => [
                    'id' => $order->hash_id,
                    'label' => $order->so_number,
                    'lines' => $order->items->map(fn ($line): array => [
                        'id' => $line->hash_id,
                        'product_id' => $line->product_id ? HashId::encode((int) $line->product_id) : null,
                        'quantity' => (string) $line->quantity_delivered,
                        'remaining_quantity' => $this->remainingOnLine((string) $line->quantity_delivered, $salesOrderReserved, (int) $line->id),
                        'unit_price' => (string) $line->unit_price,
                        'label' => 'SO line '.$line->id,
                    ])->values(),
                ])->values();

            $deliveryModels = Delivery::query()
                ->whereHas('salesOrder', fn ($query) => $query->where('customer_id', $customerId))
                ->whereNotIn('status', ['cancelled'])
                ->with(['salesOrder:id,so_number', 'items.salesOrderItem'])
                ->latest('delivered_at')
                ->limit(100)
                ->get();
            $deliveryReserved = $this->reservedFor('delivery_item', $deliveryModels);
            $deliveries = $deliveryModels
                ->map(fn (Delivery $delivery): array => [
                    'id' => $delivery->hash_id,
                    'label' => $delivery->delivery_number,
                    'sales_order_id' => $delivery->sales_order_id ? HashId::encode((int) $delivery->sales_order_id) : null,
                    'lines' => $delivery->items->map(fn ($line): array => [
                        'id' => $line->hash_id,
                        'product_id' => $line->salesOrderItem?->product_id ? HashId::encode((int) $line->salesOrderItem->product_id) : null,
                        'quantity' => (string) $line->quantity,
                        'remaining_quantity' => $this->remainingOnLine((string) $line->quantity, $deliveryReserved, (int) $line->id),
                        'unit_price' => (string) $line->unit_price,
                        'label' => 'Delivery line '.$line->id,
                    ])->values(),
                ])->values();

            return response()->json(['data' => [
                'customer' => compact('invoices', 'salesOrders', 'deliveries'),
                'supplier' => ['purchaseOrders' => [], 'goodsReceipts' => [], 'bills' => []],
            ]]);
        }

        if ($type === ReturnRequestType::SupplierReturn->value) {
            $vendorId = HashIdFilter::decode($request->query('vendor_id'), Vendor::class);
            if (! $vendorId) {
                return response()->json(['message' => 'Select a supplier to load return source documents.'], 422);
            }

            $purchaseOrders = PurchaseOrder::query()
                ->where('vendor_id', $vendorId)
                ->whereNotIn('status', ['draft', 'cancelled'])
                ->with('items')
                ->latest('date')
                ->limit(100)
                ->get()
                ->map(fn (PurchaseOrder $order): array => [
                    'id' => $order->hash_id,
                    'label' => $order->po_number,
                    'lines' => $order->items->map(fn ($line): array => [
                        'id' => $line->hash_id,
                        'item_id' => $line->item_id ? HashId::encode((int) $line->item_id) : null,
                        'quantity' => (string) $line->quantity_accepted,
                        'unit_price' => (string) $line->unit_price,
                        'label' => (string) ($line->description ?: 'PO line '.$line->id),
                    ])->values(),
                ])->values();

            // Only GRN lines carry a reservation on the supplier side —
            // `sourceLimit()` resolves 'grn_item' and no PO/bill kind — so PO and
            // bill lines deliberately expose no remaining_quantity rather than a
            // number that reserves nothing.
            $grnModels = GoodsReceiptNote::query()
                ->where('vendor_id', $vendorId)
                ->whereNotIn('status', ['draft', 'rejected'])
                ->with(['purchaseOrder:id,po_number', 'items.purchaseOrderItem'])
                ->latest('received_date')
                ->limit(100)
                ->get();
            $grnReserved = $this->reservedFor('grn_item', $grnModels);
            $goodsReceipts = $grnModels
                ->map(fn (GoodsReceiptNote $grn): array => [
                    'id' => $grn->hash_id,
                    'label' => $grn->grn_number,
                    'purchase_order_id' => $grn->purchase_order_id ? HashId::encode((int) $grn->purchase_order_id) : null,
                    'lines' => $grn->items->map(fn ($line): array => [
                        'id' => $line->hash_id,
                        'po_item_id' => $line->purchase_order_item_id ? HashId::encode((int) $line->purchase_order_item_id) : null,
                        'item_id' => $line->item_id ? HashId::encode((int) $line->item_id) : null,
                        'quantity' => (string) $line->quantity_accepted,
                        'remaining_quantity' => $this->remainingOnLine((string) $line->quantity_accepted, $grnReserved, (int) $line->id),
                        'unit_price' => (string) $line->unit_cost,
                        'lot_number' => $line->material_lot_number,
                        'label' => 'GRN line '.$line->id,
                    ])->values(),
                ])->values();

            $bills = Bill::query()
                ->where('vendor_id', $vendorId)
                ->whereNotIn('status', ['draft', 'cancelled'])
                ->with('items')
                ->latest('date')
                ->limit(100)
                ->get()
                ->map(fn (Bill $bill): array => [
                    'id' => $bill->hash_id,
                    'label' => $bill->bill_number,
                    'purchase_order_id' => $bill->purchase_order_id ? HashId::encode((int) $bill->purchase_order_id) : null,
                    'lines' => $bill->items->map(fn ($line): array => [
                        'id' => $line->hash_id,
                        'item_id' => $line->item_id ? HashId::encode((int) $line->item_id) : null,
                        'quantity' => (string) $line->quantity,
                        'unit_price' => (string) $line->unit_price,
                        'label' => (string) ($line->description ?: 'Bill line '.$line->id),
                    ])->values(),
                ])->values();

            return response()->json(['data' => [
                'customer' => ['invoices' => [], 'salesOrders' => [], 'deliveries' => []],
                'supplier' => compact('purchaseOrders', 'goodsReceipts', 'bills'),
            ]]);
        }

        return response()->json(['data' => [
            'customer' => ['invoices' => [], 'salesOrders' => [], 'deliveries' => []],
            'supplier' => ['purchaseOrders' => [], 'goodsReceipts' => [], 'bills' => []],
        ]]);
    }

    /**
     * List all RMAs.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = ReturnRequest::query()
            ->with([
                'customer:id,name',
                'vendor:id,name',
                'salesOrder:id,so_number',
                'invoice:id,invoice_number',
                'bill:id,bill_number',
                'purchaseOrder:id,po_number',
            ])->withCount('items');

        // Filters
        if ($type = $request->query('type')) {
            $q->where('type', $type);
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        // (int) on a hash_id yields 0, which matches nothing — the customer and
        // vendor filters were silently returning an empty list from the SPA.
        if ($customerId = HashIdFilter::decode($request->query('customer_id'), Customer::class)) {
            $q->where('customer_id', $customerId);
        }
        if ($vendorId = HashIdFilter::decode($request->query('vendor_id'), Vendor::class)) {
            $q->where('vendor_id', $vendorId);
        }

        // Search by RMA number
        if ($search = $request->query('search')) {
            $q->where('rma_number', 'like', "%{$search}%");
        }

        $sortField = $request->query('sort', 'created_at');
        $sortDir   = $request->query('dir', 'desc');
        $allowed   = ['rma_number', 'type', 'status', 'created_at', 'return_date'];
        if (in_array($sortField, $allowed, true)) {
            $q->orderBy($sortField, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        $perPage = min((int) $request->query('per_page', 25), 100);

        return ReturnRequestResource::collection($q->paginate($perPage));
    }

    /**
     * Show a single RMA.
     */
    public function show(ReturnRequest $returnRequest): ReturnRequestResource
    {
        $returnRequest->load([
            'items.product',
            'items.item',
            'items.ncr:id,ncr_number',
            // RMA-010 — the detail page is where an operator needs to see which
            // reservation a line holds on its source document.
            'items.sourceAllocations',
            'customer',
            'vendor',
            'salesOrder',
            'invoice',
            'purchaseOrder',
            'bill',
            'creditNote',
            'replacementPurchaseOrder',
            'creditMemo',
            'inspection',
            'inspections.product',
            'stockMovement.toLocation',
            'stockMovement.fromLocation',
            'creator:id,name',
            'approver:id,name',
            'completer:id,name',
            'rejecter:id,name',
        ]);
        $returnRequest->loadCount('items');

        return new ReturnRequestResource($returnRequest);
    }

    /**
     * Create a new RMA.
     */
    public function store(StoreReturnRequestRequest $request): ReturnRequestResource
    {
        $rma = $this->service->create($request->validated(), $request->user());

        return new ReturnRequestResource($rma->load(['items', 'customer', 'vendor', 'inspections.product']));
    }

    /** Update a still-editable draft and revalidate its source contract. */
    public function update(UpdateReturnRequestRequest $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $rma = $this->service->update($returnRequest, $request->validated(), $request->user());

        return new ReturnRequestResource($rma->load(['items', 'customer', 'vendor', 'inspections.product']));
    }

    /**
     * Submit for approval.
     */
    public function submit(ReturnRequest $returnRequest): ReturnRequestResource
    {
        $rma = $this->service->submit($returnRequest);
        return new ReturnRequestResource($rma->load(['items', 'customer', 'vendor', 'inspections.product']));
    }

    /**
     * Approve.
     */
    public function approve(ReturnRequest $returnRequest, Request $request): ReturnRequestResource
    {
        $validated = $request->validate([
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);
        $rma = $this->service->approve($returnRequest, $request->user(), $validated['remarks'] ?? null);
        return new ReturnRequestResource($rma->load(['items', 'customer', 'vendor', 'inspections.product']));
    }

    /**
     * Record receipt.
     */
    public function receive(ReceiveReturnRequest $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $rma = $this->service->receive($returnRequest, $request->receivedQuantitiesById(), $request->quarantineLocationId(), $request->user());
        return new ReturnRequestResource($rma->load(['items', 'customer', 'vendor', 'inspections.product']));
    }

    /**
     * Complete inspection.
     */
    public function inspect(Request $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $validated = $request->validate([
            'internal_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $rma = $this->service->inspect($returnRequest, $validated['internal_notes'] ?? null, $request->user());
        return new ReturnRequestResource($rma->load(['items', 'customer', 'vendor', 'inspections.product']));
    }

    /** Retry a failed RMA → Quality inspection handoff. */
    public function retryInspection(Request $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $rma = $this->service->retryInspectionHandoff($returnRequest, $request->user());

        return new ReturnRequestResource($rma->load(['items', 'customer', 'vendor', 'inspection', 'inspections.product']));
    }

    /**
     * Dispose items on an inspected RMA.
     */
    public function dispose(DisposeReturnRequest $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        return new ReturnRequestResource(
            $this->service->dispose(
                $returnRequest,
                $request->validated()['dispositions'],
                $request->user(),
                (bool) ($request->validated()['create_replacement_po'] ?? false),
                isset($request->validated()['location_id']) ? (int) $request->validated()['location_id'] : null,
            )
        );
    }

    /**
     * Complete the RMA.
     */
    public function complete(CompleteReturnRequest $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $locationId = isset($request->validated()['location_id'])
            ? (int) $request->validated()['location_id']
            : null;
        $rma = $this->service->complete($returnRequest, $request->user(), $locationId);
        return new ReturnRequestResource($rma->load(['items', 'customer', 'vendor', 'inspections.product', 'stockMovement.toLocation', 'stockMovement.fromLocation']));
    }

    /**
     * Reject.
     */
    public function reject(Request $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $rma = $this->service->reject($returnRequest, $request->user(), $validated['reason']);
        return new ReturnRequestResource($rma->load(['items', 'customer', 'vendor', 'inspections.product']));
    }

    /**
     * Cancel.
     */
    public function cancel(Request $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $rma = $this->service->cancel($returnRequest, $validated['reason'] ?? null);
        return new ReturnRequestResource($rma->load(['items', 'customer', 'vendor', 'inspections.product']));
    }

}
