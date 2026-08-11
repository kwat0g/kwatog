<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Controllers;

use App\Common\Services\SettingsService;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use App\Modules\ReturnManagement\Enums\DispositionType;
use App\Common\Support\HashIdFilter;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\ReturnManagement\Requests\CompleteReturnRequest;
use App\Modules\ReturnManagement\Requests\DisposeReturnRequest;
use App\Modules\ReturnManagement\Requests\ReceiveReturnRequest;
use App\Modules\ReturnManagement\Requests\StoreReturnRequestRequest;
use App\Modules\ReturnManagement\Resources\ReturnRequestResource;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class ReturnRequestController extends Controller
{
    public function __construct(
        private readonly ReturnRequestService $service,
        private readonly SettingsService $settings,
    ) {}

    public function options(): \Illuminate\Http\JsonResponse
    {
        $read = fn (string $key): array => array_values(array_filter(
            (array) $this->settings->get($key, []),
            static fn ($option): bool => is_array($option) && isset($option['value'], $option['label']),
        ));

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
            'dispositions' => array_map(
                static fn (DispositionType $disposition): array => ['value' => $disposition->value, 'label' => $disposition->label()],
                DispositionType::cases(),
            ),
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
            'stockMovement.toLocation',
            'stockMovement.fromLocation',
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
    public function store(StoreReturnRequestRequest $request): ReturnRequestResource
    {
        $rma = $this->service->create($request->validated(), $request->user());

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
    public function receive(ReceiveReturnRequest $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $rma = $this->service->receive($returnRequest, $request->receivedQuantitiesById());
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

    /** Retry a failed RMA → Quality inspection handoff. */
    public function retryInspection(Request $request, ReturnRequest $returnRequest): ReturnRequestResource
    {
        $rma = $this->service->retryInspectionHandoff($returnRequest, $request->user());

        return new ReturnRequestResource($rma->load(['items', 'customer', 'vendor', 'inspection']));
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
        return new ReturnRequestResource($rma->load(['items', 'customer', 'vendor', 'stockMovement.toLocation', 'stockMovement.fromLocation']));
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
