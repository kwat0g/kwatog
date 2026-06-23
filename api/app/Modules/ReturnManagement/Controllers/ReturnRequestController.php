<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Controllers;

use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Resources\ReturnRequestResource;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class ReturnRequestController extends Controller
{
    public function __construct(
        private readonly ReturnRequestService $service,
    ) {}

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
        if ($customerId = $request->query('customer_id')) {
            $q->where('customer_id', (int) $customerId);
        }
        if ($vendorId = $request->query('vendor_id')) {
            $q->where('vendor_id', (int) $vendorId);
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
            'customer',
            'vendor',
            'salesOrder',
            'invoice',
            'purchaseOrder',
            'bill',
            'creditNote',
            'creator:id,name',
            'approver:id,name',
            'completer:id,name',
        ]);
        $returnRequest->loadCount('items');

        return new ReturnRequestResource($returnRequest);
    }

    /**
     * Create a new RMA.
     */
    public function store(Request $request): ReturnRequestResource
    {
        $validated = $request->validate([
            'type'                => ['required', 'string', 'in:customer_return,supplier_return'],
            'sales_order_id'      => ['nullable', 'exists:sales_orders,id'],
            'invoice_id'          => ['nullable', 'exists:invoices,id'],
            'purchase_order_id'   => ['nullable', 'exists:purchase_orders,id'],
            'bill_id'             => ['nullable', 'exists:bills,id'],
            'customer_id'         => ['nullable', 'exists:customers,id'],
            'vendor_id'           => ['nullable', 'exists:vendors,id'],
            'reason_code'         => ['nullable', 'string', 'max:30'],
            'reason_description'  => ['nullable', 'string', 'max:1000'],
            'customer_notes'      => ['nullable', 'string', 'max:2000'],
            'resolution'          => ['nullable', 'string', 'max:30'],
            'return_date'         => ['nullable', 'date'],
            'items'               => ['nullable', 'array'],
            'items.*.product_id'  => ['nullable', 'exists:products,id'],
            'items.*.item_id'     => ['nullable', 'exists:items,id'],
            'items.*.quantity'    => ['required_with:items', 'numeric', 'min:0.001'],
            'items.*.unit_price'  => ['nullable', 'numeric', 'min:0'],
            'items.*.reason'      => ['nullable', 'string', 'max:500'],
            'items.*.condition'   => ['nullable', 'string', 'max:30'],
        ]);

        $rma = $this->service->create($validated, $request->user());

        return new ReturnRequestResource($rma->load(['items', 'customer', 'vendor']));
    }

    /**
     * Submit for approval.
     */
    public function submit(ReturnRequest $returnRequest): ReturnRequestResource
    {
        $rma = $this->service->submit($returnRequest);
        return new ReturnRequestResource($rma->load(['items', 'customer', 'vendor']));
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
        return new ReturnRequestResource($rma->load(['items', 'customer', 'vendor']));
    }

    /**
     * Record receipt.
     */
    public function receive(Request $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $validated = $request->validate([
            'received_quantities' => ['nullable', 'array'],
            'received_quantities.*' => ['numeric', 'min:0'],
        ]);

        $rma = $this->service->receive($returnRequest, $validated['received_quantities'] ?? []);
        return new ReturnRequestResource($rma->load(['items', 'customer', 'vendor']));
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
        return new ReturnRequestResource($rma->load(['items', 'customer', 'vendor']));
    }

    /**
     * Complete the RMA.
     */
    public function complete(Request $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $validated = $request->validate([
            'location_id' => ['required', 'exists:warehouse_locations,id'],
        ]);

        $rma = $this->service->complete($returnRequest, $request->user(), (int) $validated['location_id']);
        return new ReturnRequestResource($rma->load(['items', 'customer', 'vendor', 'stockMovement']));
    }

    /**
     * Reject.
     */
    public function reject(Request $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $rma = $this->service->reject($returnRequest, $validated['reason'] ?? null);
        return new ReturnRequestResource($rma->load(['items', 'customer', 'vendor']));
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
        return new ReturnRequestResource($rma->load(['items', 'customer', 'vendor']));
    }

}
