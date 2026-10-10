<?php

declare(strict_types=1);

namespace App\Modules\Production\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Services\CapacityPlanningService;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Requests\ExecuteWorkOrderRequest;
use App\Modules\Production\Requests\RecordOutputRequest;
use App\Modules\Production\Requests\ReviewWorkOrderSlotRequest;
use App\Modules\Production\Resources\WorkOrderOutputResource;
use App\Modules\Production\Resources\WorkOrderResource;
use App\Modules\Production\Services\WorkOrderReworkService;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class WorkOrderReworkController
{
    public function suggestion(ReviewWorkOrderSlotRequest $request, WorkOrder $workOrder, NonConformanceReport $ncr): JsonResponse
    {
        return response()->json(['data' => app(CapacityPlanningService::class)->suggestRepair($workOrder, $ncr, $request->validated())]);
    }

    public function reviewSlot(ReviewWorkOrderSlotRequest $request, WorkOrder $workOrder, NonConformanceReport $ncr): WorkOrderResource
    {
        $data = $request->validated();
        app(WorkOrderReworkService::class)->reviewSlot($workOrder, $ncr,
            Carbon::parse($data['scheduled_start'])->timezone(config('app.timezone')),
            Carbon::parse($data['scheduled_end'])->timezone(config('app.timezone')),
            $request->user(), $data['reason'], $data['machine_id'] ?? null, $data['mold_id'] ?? null, $data);

        return new WorkOrderResource(app(WorkOrderService::class)->show($workOrder));
    }

    public function start(ExecuteWorkOrderRequest $request, WorkOrder $workOrder, NonConformanceReport $ncr): WorkOrderResource
    {
        $data = $request->validate(['machine_id' => ['nullable', 'string'], 'mold_id' => ['nullable', 'string']]);
        $machine = empty($data['machine_id']) ? null : Machine::tryDecodeHash($data['machine_id']);
        $mold = empty($data['mold_id']) ? null : Mold::tryDecodeHash($data['mold_id']);
        if ((! empty($data['machine_id']) && ! $machine) || (! empty($data['mold_id']) && ! $mold)) {
            throw ValidationException::withMessages(['machine_id' => 'Select valid repair equipment.']);
        }
        app(WorkOrderReworkService::class)->start($workOrder, $ncr, $request->user(), $machine, $mold, $request->validated());

        return new WorkOrderResource(app(WorkOrderService::class)->show($workOrder));
    }

    public function record(RecordOutputRequest $request, WorkOrder $workOrder, NonConformanceReport $ncr): WorkOrderOutputResource
    {
        $key = $request->header('X-Idempotency-Key');
        if ($key !== null && (! preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $key))) {
            throw new BusinessRuleException('Invalid output idempotency key.');
        }

        return new WorkOrderOutputResource(app(WorkOrderReworkService::class)->record($workOrder, $ncr, $request->validated(), $request->user(), $key));
    }

    public function finish(Request $request, WorkOrder $workOrder, NonConformanceReport $ncr): WorkOrderResource
    {
        app(WorkOrderReworkService::class)->finish($workOrder, $ncr, $request->user());

        return new WorkOrderResource(app(WorkOrderService::class)->show($workOrder));
    }
}
