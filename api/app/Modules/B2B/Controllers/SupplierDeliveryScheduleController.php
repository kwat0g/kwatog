<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Modules\B2B\Models\DeliverySchedule;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\B2B\Requests\Supplier\CancelDeliveryScheduleRequest;
use App\Modules\B2B\Requests\Supplier\StoreDeliveryScheduleRequest;
use App\Modules\B2B\Resources\DeliveryScheduleResource;
use App\Modules\B2B\Services\SupplierDeliveryScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * Supplier delivery schedules (monthly delivery plans per PO).
 */
class SupplierDeliveryScheduleController extends Controller
{
    public function __construct(
        private readonly SupplierDeliveryScheduleService $service,
    ) {}

    private function user(Request $request): SupplierPortalUser
    {
        /** @var SupplierPortalUser $user */
        $user = $request->user('supplier_portal');
        return $user;
    }

    /**
     * GET /api/v1/b2b/supplier/delivery-schedules
     */
    public function deliverySchedules(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $schedules = $this->service->deliverySchedules($user->vendor_id, [
            'status' => $request->query('status'),
            'purchase_order_id' => $request->query('purchase_order_id'),
            'per_page' => $request->query('per_page', 25),
        ]);

        return DeliveryScheduleResource::collection($schedules);
    }

    /**
     * POST /api/v1/b2b/supplier/delivery-schedules
     */
    public function storeDeliverySchedule(StoreDeliveryScheduleRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $schedule = $this->service->storeDeliverySchedule($user->vendor_id, $user->id, $request->validated());

        return response()->json([
            'data'    => new DeliveryScheduleResource($schedule),
            'message' => 'Delivery schedule submitted successfully.',
        ], 201);
    }

    /**
     * POST /api/v1/b2b/supplier/delivery-schedules/{deliverySchedule}/cancel
     */
    public function cancel(DeliverySchedule $deliverySchedule, CancelDeliveryScheduleRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $schedule = $this->service->cancel($deliverySchedule, $user->vendor_id, $user->id, $request->validated('reason'));

        return response()->json([
            'data'    => new DeliveryScheduleResource($schedule),
            'message' => 'Delivery schedule cancelled.',
        ]);
    }

    /**
     * GET /api/v1/b2b/supplier/delivery-schedules/purchase-orders
     */
    public function eligiblePurchaseOrders(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->schedulablePurchaseOrders($this->user($request)->vendor_id)]);
    }
}
