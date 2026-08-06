<?php

declare(strict_types=1);

namespace App\Modules\MRP\Controllers;

use App\Modules\CRM\Models\SalesOrder;
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\MRP\Enums\MrpPlanStatus;
use App\Modules\MRP\Resources\MrpPlanResource;
use App\Modules\MRP\Services\MrpEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MrpPlanController
{
    public function __construct(private readonly MrpEngineService $engine) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return MrpPlanResource::collection($this->engine->list($request->query()));
    }

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'statuses' => array_map(static fn (MrpPlanStatus $status): array => [
                'value' => $status->value,
                'label' => ucfirst($status->value),
            ], MrpPlanStatus::cases()),
        ]]);
    }

    public function show(MrpPlan $mrpPlan): MrpPlanResource
    {
        return new MrpPlanResource($this->engine->show($mrpPlan));
    }

    public function rerun(MrpPlan $mrpPlan): MrpPlanResource
    {
        return new MrpPlanResource($this->engine->rerun($mrpPlan));
    }

    /** GET /sales-orders/{so}/mrp-plan — returns active plan or null. */
    public function forSalesOrder(SalesOrder $salesOrder): MrpPlanResource|JsonResponse
    {
        $plan = MrpPlan::where('sales_order_id', $salesOrder->id)
            ->where('status', 'active')
            ->orderByDesc('version')
            ->first();
        if (! $plan) {
            return response()->json(['data' => null]);
        }
        return new MrpPlanResource($this->engine->show($plan));
    }
}
