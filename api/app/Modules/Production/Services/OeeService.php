<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Enums\MachineDowntimeCategory;
use App\Modules\Production\Models\MachineDowntime;
use App\Modules\Production\Models\WorkOrderOutput;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Sprint 6 — Task 57. OEE = Availability × Performance × Quality.
 *
 * All four metrics are returned as 0..1 floats with cap at 1.0; the UI
 * renders them as percentages with one decimal. Diagnostics field exposes
 * every input so the panel can show a "?" tooltip with the math.
 *
 * Math (per the Sprint 6 plan §0):
 *   scheduled_minutes = available_hours_per_day * working_days_in_window * 60
 *   planned_downtime  = Σ duration_minutes WHERE category IN (planned_maintenance, changeover)
 *   unplanned_downtime= Σ duration_minutes WHERE category IN (breakdown, material_shortage, no_order)
 *   available_time    = max(0, scheduled - planned_downtime)
 *   run_time          = max(0, available_time - unplanned_downtime)
 *   ideal_cycle_secs  = avg(mold.cycle_time_seconds) over outputs in window
 *   good / reject     = Σ over work_order_outputs in window
 *   availability      = run_time / available_time
 *   performance       = min(1, ((good + reject) * ideal_cycle / 60) / run_time)
 *   quality           = good / (good + reject)
 *   oee               = availability * performance * quality
 *
 * Edge cases handled:
 *   - zero scheduled time → all zeros, no NaN
 *   - performance > 1 (stale ideal_cycle) → clamped to 1, diagnostics.performance_capped=true
 *   - zero outputs → quality = 0 (treat as no good production)
 */
class OeeService
{
    public function calculate(Machine $machine, Carbon $from, Carbon $to): array
    {
        $scheduledMinutes = $this->scheduledMinutes($machine, $from, $to);

        $planned = (int) MachineDowntime::where('machine_id', $machine->id)
            ->whereIn('category', [
                MachineDowntimeCategory::PlannedMaintenance->value,
                MachineDowntimeCategory::Changeover->value,
            ])
            ->whereBetween('start_time', [$from, $to])
            ->whereNotNull('duration_minutes')
            ->sum('duration_minutes');

        $unplanned = (int) MachineDowntime::where('machine_id', $machine->id)
            ->whereIn('category', [
                MachineDowntimeCategory::Breakdown->value,
                MachineDowntimeCategory::MaterialShortage->value,
                MachineDowntimeCategory::NoOrder->value,
            ])
            ->whereBetween('start_time', [$from, $to])
            ->whereNotNull('duration_minutes')
            ->sum('duration_minutes');

        $availableTime = max(0, $scheduledMinutes - $planned);
        $runTime       = max(0, $availableTime - $unplanned);

        $outputs = WorkOrderOutput::with('workOrder.mold')
            ->whereHas('workOrder', fn ($q) => $q->where('machine_id', $machine->id))
            ->whereBetween('recorded_at', [$from, $to])
            ->get();

        $good   = (int) $outputs->sum('good_count');
        $reject = (int) $outputs->sum('reject_count');
        $total  = $good + $reject;

        $idealCycle = 0.0;
        if ($outputs->isNotEmpty()) {
            $cycles = $outputs
                ->map(fn ($o) => (int) ($o->workOrder?->mold?->cycle_time_seconds ?? 0))
                ->filter(fn ($c) => $c > 0);
            $idealCycle = $cycles->isNotEmpty() ? (float) $cycles->avg() : 0.0;
        }

        $availability = $availableTime > 0 ? $runTime / $availableTime : 0.0;
        $performanceRaw = $runTime > 0
            ? ($total * $idealCycle / 60.0) / $runTime
            : 0.0;
        $performance = min(1.0, max(0.0, $performanceRaw));
        $quality = $total > 0 ? $good / $total : 0.0;
        $oee = $availability * $performance * $quality;

        return [
            'availability' => round($availability, 4),
            'performance'  => round($performance, 4),
            'quality'      => round($quality, 4),
            'oee'          => round($oee, 4),
            'diagnostics'  => [
                'scheduled_minutes'   => $scheduledMinutes,
                'planned_downtime'    => $planned,
                'unplanned_downtime'  => $unplanned,
                'available_time'      => $availableTime,
                'run_time'            => $runTime,
                'good_count'          => $good,
                'reject_count'        => $reject,
                'ideal_cycle_seconds' => round($idealCycle, 2),
                'performance_capped'  => $performanceRaw > 1.0,
            ],
            'period_from'  => $from->toIso8601String(),
            'period_to'    => $to->toIso8601String(),
        ];
    }

    /**
     * Scheduled minutes assumed = available_hours_per_day * working_days * 60.
     * Working days here is a simple weekday count between $from and $to.
     */
    private function scheduledMinutes(Machine $machine, Carbon $from, Carbon $to): int
    {
        $workingDays = 0;
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        while ($cursor->lte($end)) {
            if (! $cursor->isWeekend()) {
                $workingDays++;
            }
            $cursor->addDay();
        }
        return (int) round((float) $machine->available_hours_per_day * $workingDays * 60);
    }

    /**
     * Bulk OEE for every active machine over a single window. Returns one row
     * per machine; used by the Sprint 6 Task 58 dashboard.
     */
    public function calculateForAllMachines(Carbon $from, Carbon $to): Collection
    {
        return Machine::orderBy('machine_code')
            ->get()
            ->map(function ($m) use ($from, $to) {
                return [
                    'machine_id'   => $m->hash_id,
                    'machine_code' => $m->machine_code,
                    'name'         => $m->name,
                    'tonnage'      => $m->tonnage,
                    'status'       => (string) $m->status?->value,
                ] + $this->calculate($m, $from, $to);
            });
    }

    public function calculateForToday(Machine $m): array
    {
        $today = Carbon::today();
        return $this->calculate($m, $today, $today->copy()->endOfDay());
    }
}
