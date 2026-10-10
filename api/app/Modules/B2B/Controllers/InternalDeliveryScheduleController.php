<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Modules\B2B\Models\DeliverySchedule;
use App\Modules\B2B\Requests\Internal\RejectDeliveryScheduleRequest;
use App\Modules\B2B\Resources\DeliveryScheduleResource;
use App\Modules\B2B\Services\DeliveryScheduleReviewService;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Policies\PurchaseOrderAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InternalDeliveryScheduleController
{
    public function __construct(
        private readonly DeliveryScheduleReviewService $service,
        private readonly PurchaseOrderAccessPolicy $access,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'string', 'max:32'],
            'source' => ['nullable', 'in:customer,supplier'],
            'month' => ['nullable', 'string', 'max:7'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return DeliveryScheduleResource::collection($this->service->list($validated));
    }

    public function show(DeliverySchedule $deliverySchedule): DeliveryScheduleResource
    {
        return new DeliveryScheduleResource($this->service->show($deliverySchedule));
    }

    public function acknowledge(DeliverySchedule $deliverySchedule): JsonResponse
    {
        $schedule = $this->service->acknowledge($deliverySchedule, auth()->user());

        return response()->json([
            'data' => new DeliveryScheduleResource($schedule),
            'message' => 'Delivery schedule acknowledged.',
        ]);
    }

    public function reject(RejectDeliveryScheduleRequest $request, DeliverySchedule $deliverySchedule): JsonResponse
    {
        $schedule = $this->service->reject($deliverySchedule, $request->validated()['reason'], auth()->user());

        return response()->json([
            'data' => new DeliveryScheduleResource($schedule),
            'message' => 'Delivery schedule rejected.',
        ]);
    }

    public function acknowledgeSupplier(Request $request, PurchaseOrder $purchaseOrder, DeliverySchedule $deliverySchedule): JsonResponse
    {
        $this->assertSupplierPlanAccess($request, $purchaseOrder, $deliverySchedule);
        $schedule = $this->service->acknowledge($deliverySchedule, $request->user());

        return response()->json(['data' => new DeliveryScheduleResource($schedule), 'message' => 'Supplier delivery plan acknowledged.']);
    }

    public function rejectSupplier(RejectDeliveryScheduleRequest $request, PurchaseOrder $purchaseOrder, DeliverySchedule $deliverySchedule): JsonResponse
    {
        $this->assertSupplierPlanAccess($request, $purchaseOrder, $deliverySchedule);
        $schedule = $this->service->reject($deliverySchedule, $request->validated()['reason'], $request->user());

        return response()->json(['data' => new DeliveryScheduleResource($schedule), 'message' => 'Supplier delivery plan rejected.']);
    }

    private function assertSupplierPlanAccess(Request $request, PurchaseOrder $purchaseOrder, DeliverySchedule $schedule): void
    {
        abort_unless($this->access->canView($request->user(), $purchaseOrder)
            && (int) $schedule->purchase_order_id === (int) $purchaseOrder->id
            && (int) $schedule->vendor_id === (int) $purchaseOrder->vendor_id
            && $schedule->customer_id === null, 404);
    }
}
