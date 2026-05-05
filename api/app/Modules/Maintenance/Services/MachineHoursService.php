<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Services;

use App\Modules\MRP\Models\Machine;
use Illuminate\Support\Facades\DB;

/**
 * Task A5 — Running hours tracker for machines.
 *
 * Recompute logic per machine, lifetime:
 *   running_hours_total =
 *     SUM over work_order_outputs.total_minutes / 60
 *     − SUM over machine_downtimes.duration_minutes / 60
 *
 * Falls back to a simple proxy when WO outputs lack duration: counts each
 * output as 1 hour. This is a best-effort tally — its only consumer is the
 * preventive-maintenance evaluator which compares deltas, not absolutes.
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
        // Sum of WO outputs as a proxy for run time. If a `total_minutes`
        // column exists, use it; otherwise count each output as 60 min.
        $hasMinutes = DB::getSchemaBuilder()->hasColumn('work_order_outputs', 'total_minutes');

        if ($hasMinutes) {
            $output = (float) DB::table('work_order_outputs as wo')
                ->join('work_orders as w', 'w.id', '=', 'wo.work_order_id')
                ->where('w.machine_id', $machineId)
                ->sum('wo.total_minutes');
        } else {
            $count = (int) DB::table('work_order_outputs as wo')
                ->join('work_orders as w', 'w.id', '=', 'wo.work_order_id')
                ->where('w.machine_id', $machineId)
                ->count();
            $output = $count * 60.0;
        }

        $downtime = (float) DB::table('machine_downtimes')
            ->where('machine_id', $machineId)
            ->sum('duration_minutes');

        return max(0.0, ($output - $downtime) / 60.0);
    }
}
