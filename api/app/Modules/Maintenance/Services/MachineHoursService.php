<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Services;

use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Task A5 — Running hours tracker for machines.
 *
 * Recompute logic uses persisted work-order start/end timestamps and subtracts
 * recorded downtime. Output-row counts are never converted into invented time.
 */
class MachineHoursService
{
    public function recompute(): int
    {
        $count = 0;
        Machine::query()->orderBy('id')->chunk(50, function ($machines) use (&$count) {
            foreach ($machines as $machine) {
                $hours = $this->computeForMachine((int) $machine->id);
                $machine->forceFill([
                    'running_hours_total'      => round($hours, 2),
                    'running_hours_updated_at' => now(),
                ])->save();
                $count++;
            }
        });
        return $count;
    }

    private function computeForMachine(int $machineId): float
    {
        $output = WorkOrder::query()
            ->where('machine_id', $machineId)
            ->whereNotNull('actual_start')
            ->get(['actual_start', 'actual_end'])
            ->sum(function (WorkOrder $workOrder): float {
                $start = Carbon::parse($workOrder->actual_start);
                $end = $workOrder->actual_end ? Carbon::parse($workOrder->actual_end) : now();
                return $end->greaterThan($start) ? $start->diffInMinutes($end, true) : 0.0;
            });

        $downtime = (float) DB::table('machine_downtimes')
            ->where('machine_id', $machineId)
            ->sum('duration_minutes');

        return max(0.0, ($output - $downtime) / 60.0);
    }
}
