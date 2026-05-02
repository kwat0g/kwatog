<?php

declare(strict_types=1);

namespace App\Modules\MRP\Controllers;

use App\Common\Support\HashIdFilter;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Requests\ConfirmScheduleRequest;
use App\Modules\MRP\Requests\RunSchedulerRequest;
use App\Modules\MRP\Services\CapacityPlanningService;
use App\Modules\Production\Models\ProductionSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SchedulerController
{
    public function __construct(private readonly CapacityPlanningService $service) {}

    public function run(RunSchedulerRequest $request): JsonResponse
    {
        $result = $this->service->run($request->input('work_order_ids'));
        return response()->json(['data' => $result]);
    }

    public function confirm(ConfirmScheduleRequest $request): JsonResponse
    {
        $confirmed = $this->service->confirm(
            $request->validated()['schedule_ids'],
            $request->user()->id,
        );
        return response()->json(['data' => [
            'confirmed_count' => $confirmed->count(),
            'schedule_ids'    => $confirmed->pluck('hash_id')->all(),
        ]]);
    }

    public function reorder(Request $request, ProductionSchedule $schedule): JsonResponse
    {
        if (! $request->user()->hasPermission('mrp.schedule')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $newOrder = (int) $request->input('priority_order', 0);
        $this->service->reorder($schedule->id, $newOrder);
        return response()->json(['message' => 'Reordered.']);
    }

    public function reassign(Request $request, ProductionSchedule $schedule): JsonResponse
    {
        if (! $request->user()->hasPermission('mrp.schedule')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $machineId = HashIdFilter::decode($request->input('machine_id'), Machine::class);
        $moldId    = HashIdFilter::decode($request->input('mold_id'), Mold::class);
        if (! $machineId || ! $moldId) {
            return response()->json(['message' => 'Invalid machine_id or mold_id.'], 422);
        }
        $this->service->reassign($schedule->id, $machineId, $moldId);
        return response()->json(['message' => 'Reassigned.']);
    }

    public function snapshot(Request $request): JsonResponse
    {
        $from = Carbon::parse($request->query('from', Carbon::today()->toDateString()));
        $to   = Carbon::parse($request->query('to', Carbon::today()->copy()->addDays(14)->toDateString()));
        return response()->json(['data' => $this->service->snapshot($from, $to)]);
    }
}
