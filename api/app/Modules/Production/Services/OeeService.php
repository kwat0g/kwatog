<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Enums\MachineDowntimeCategory;
use App\Modules\Production\Models\MachineDowntime;
use App\Modules\Production\Models\WorkOrderOutput;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 6 — Task 57. OEE = Availability × Performance × Quality.
 *
 * Measured metrics are returned as 0..1 floats with cap at 1.0; a metric is
 * null when the source window has no observations for it. The UI renders
 * them as percentages with one decimal. Diagnostics field exposes
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
 *   - zero scheduled time or no output → null for the unmeasurable metrics
 *   - performance > 1 (stale ideal_cycle) → clamped to 1, diagnostics.performance_capped=true
 *   - zero outputs → quality/performance/OEE are null (no measured result)
 */
class OeeService
{
    /** @var array<int, string> */
    private const ACTIVE_MACHINE_STATUSES = [
        MachineStatus::Idle->value,
        MachineStatus::Running->value,
    ];

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

        $outputs = WorkOrderOutput::with('workOrder.mold')
            ->whereHas('workOrder', fn ($q) => $q->where('machine_id', $machine->id))
            ->whereBetween('recorded_at', [$from, $to])
            ->get();

        $good = (int) $outputs->sum('good_count');
        $reject = (int) $outputs->sum('reject_count');
        $total = $good + $reject;

        $idealCycle = 0.0;
        if ($outputs->isNotEmpty()) {
            $cycles = $outputs
                ->map(fn ($o) => (int) ($o->workOrder?->mold?->cycle_time_seconds ?? 0))
                ->filter(fn ($c) => $c > 0);
            $idealCycle = $cycles->isNotEmpty() ? (float) $cycles->avg() : 0.0;
        }

        return $this->metrics(
            $machine,
            $from,
            $to,
            $scheduledMinutes,
            $planned,
            $unplanned,
            $good,
            $reject,
            $idealCycle,
        );
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
        return Machine::query()
            ->whereIn('status', self::ACTIVE_MACHINE_STATUSES)
            ->with('currentWorkOrder.mold')
            ->orderBy('machine_code')
            ->get()
            ->map(function ($m) use ($from, $to) {
                $currentWorkOrder = $m->currentWorkOrder;

                return [
                    'machine_id' => $m->hash_id,
                    'machine_code' => $m->machine_code,
                    'name' => $m->name,
                    'tonnage' => $m->tonnage,
                    'status' => (string) $m->status?->value,
                    'status_label' => MachineStatus::tryFrom((string) $m->status?->value)?->label() ?? (string) $m->status?->value,
                    'active_wo' => $currentWorkOrder?->wo_number,
                    'active_mold' => $currentWorkOrder?->mold?->mold_code,
                    'current_output' => $currentWorkOrder?->quantity_produced !== null
                        ? (int) $currentWorkOrder->quantity_produced
                        : null,
                    'target_output' => $currentWorkOrder?->quantity_target !== null
                        ? (int) $currentWorkOrder->quantity_target
                        : null,
                    'cycle_time_sec' => $currentWorkOrder?->mold?->cycle_time_seconds,
                ] + $this->calculate($m, $from, $to);
            });
    }

    public function calculateForToday(Machine $m): array
    {
        $today = Carbon::today();

        return $this->calculate($m, $today, $today->copy()->endOfDay());
    }

    /**
     * Sprint P10 — full OEE report.
     *
     * Returns aggregated overall metrics, per-machine rows, daily trend, and a
     * downtime-by-category breakdown for the given window. Used by the
     * `/production/oee` page.
     *
     * @param  Carbon  $from  Start of window (inclusive).
     * @param  Carbon  $to  End of window (inclusive).
     * @param  Machine|null  $onlyMachine  If provided, scope the report to a
     *                                     single machine. Otherwise report on all
     *                                     active machines.
     * @return array{
     *     range: array{from:string,to:string},
     *     overall: array{availability:float|null,performance:float|null,quality:float|null,oee:float|null},
     *     machines: Collection,
     *     trend: array<int, array{date:string,oee:float}>,
     *     downtime_breakdown: array<int, array{category:string,minutes:int}>
     * }
     */
    public function report(Carbon $from, Carbon $to, ?Machine $onlyMachine = null): array
    {
        $machines = $onlyMachine
            ? collect([$onlyMachine])
            : Machine::query()
                ->whereIn('status', self::ACTIVE_MACHINE_STATUSES)
                ->orderBy('machine_code')
                ->get();
        $machineRows = $machines->map(fn ($m) => $this->machineRow($m, $from, $to));

        // Aggregate overall metrics — straight average across machines that
        // had any production activity (run_time > 0). Machines with no
        // schedule contribute zeros and would tank the average otherwise.
        $active = $machineRows->filter(fn ($r) => ($r['diagnostics']['run_time'] ?? 0) > 0);
        $overallAvg = static function (string $field) use ($active): ?float {
            // No running machine means there is no measured OEE for this
            // window. Returning zero would present missing evidence as a
            // genuine quality/performance result.
            $measured = $active->filter(static fn (array $row): bool => $row[$field] !== null);

            return $measured->isEmpty() ? null : round((float) $measured->avg($field), 4);
        };
        $overall = [
            'availability' => $overallAvg('availability'),
            'performance' => $overallAvg('performance'),
            'quality' => $overallAvg('quality'),
            'oee' => $overallAvg('oee'),
        ];

        // Daily points are retained for short windows. Longer windows use
        // weekly buckets and one batched input load instead of days × machines
        // calls to calculate().
        $trend = $this->trend($from, $to, $machines);

        // Downtime by category across all machines in scope.
        $downtimeQuery = MachineDowntime::query()
            ->whereBetween('start_time', [$from, $to])
            ->whereNotNull('duration_minutes');
        $downtimeQuery->whereIn('machine_id', $machines->pluck('id')->all());
        $downtimeRows = $downtimeQuery
            ->selectRaw('category, SUM(duration_minutes) as minutes')
            ->groupBy('category')
            ->orderByDesc('minutes')
            ->get()
            ->map(fn ($r) => [
                'category' => $r->category instanceof \UnitEnum ? (string) $r->category->value : (string) $r->category,
                'minutes' => (int) $r->minutes,
            ])
            ->all();

        return [
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'overall' => $overall,
            'machines' => $machineRows->values(),
            'trend' => $trend,
            'downtime_breakdown' => $downtimeRows,
        ];
    }

    private function machineRow(Machine $m, Carbon $from, Carbon $to): array
    {
        return [
            'machine_id' => $m->hash_id,
            'machine_code' => $m->machine_code,
            'name' => $m->name,
            'tonnage' => $m->tonnage,
            'status' => (string) $m->status?->value,
            'status_label' => MachineStatus::tryFrom((string) $m->status?->value)?->label() ?? (string) $m->status?->value,
        ] + $this->calculate($m, $from, $to);
    }

    /** @param Collection<int, Machine> $machines */
    private function trend(Carbon $from, Carbon $to, Collection $machines): array
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();
        $days = $start->diffInDays($end->copy()->startOfDay()) + 1;
        $stepDays = $days <= 92 ? 1 : 7;
        $buckets = [];
        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDays($stepDays)) {
            $bucketEnd = $cursor->copy()->addDays($stepDays - 1)->endOfDay();
            if ($bucketEnd->gt($end)) {
                $bucketEnd = $end->copy();
            }
            $buckets[] = ['start' => $cursor->copy(), 'end' => $bucketEnd];
        }

        $machineIds = $machines->pluck('id')->all();
        if ($machineIds === []) {
            return array_map(fn (array $bucket): array => [
                'date' => $bucket['start']->toDateString(),
                'oee' => null,
            ], $buckets);
        }

        $aggregates = [];
        foreach ($buckets as $index => $bucket) {
            foreach ($machineIds as $machineId) {
                $aggregates[$index][$machineId] = [
                    'planned' => 0,
                    'unplanned' => 0,
                    'good' => 0,
                    'reject' => 0,
                    'cycles' => [],
                ];
            }
        }

        $downtimes = DB::table('machine_downtimes')
            ->whereIn('machine_id', $machineIds)
            ->whereBetween('start_time', [$from, $to])
            ->whereNotNull('duration_minutes')
            ->get(['machine_id', 'start_time', 'duration_minutes', 'category']);
        foreach ($downtimes as $downtime) {
            $bucket = $this->bucketIndex(Carbon::parse($downtime->start_time), $buckets);
            if ($bucket === null) {
                continue;
            }
            $minutes = (int) $downtime->duration_minutes;
            if (in_array($downtime->category, [
                MachineDowntimeCategory::PlannedMaintenance->value,
                MachineDowntimeCategory::Changeover->value,
            ], true)) {
                $aggregates[$bucket][$downtime->machine_id]['planned'] += $minutes;
            } elseif (in_array($downtime->category, [
                MachineDowntimeCategory::Breakdown->value,
                MachineDowntimeCategory::MaterialShortage->value,
                MachineDowntimeCategory::NoOrder->value,
            ], true)) {
                $aggregates[$bucket][$downtime->machine_id]['unplanned'] += $minutes;
            }
        }

        $outputs = DB::table('work_order_outputs as output')
            ->join('work_orders as wo', 'wo.id', '=', 'output.work_order_id')
            ->leftJoin('molds as mold', function ($join): void {
                $join->on('mold.id', '=', 'wo.mold_id')
                    ->whereNull('mold.deleted_at');
            })
            ->whereIn('wo.machine_id', $machineIds)
            ->whereBetween('output.recorded_at', [$from, $to])
            ->get([
                'wo.machine_id',
                'output.recorded_at',
                'output.good_count',
                'output.reject_count',
                'mold.cycle_time_seconds',
            ]);
        foreach ($outputs as $output) {
            $bucket = $this->bucketIndex(Carbon::parse($output->recorded_at), $buckets);
            if ($bucket === null) {
                continue;
            }
            $aggregate = &$aggregates[$bucket][$output->machine_id];
            $aggregate['good'] += (int) $output->good_count;
            $aggregate['reject'] += (int) $output->reject_count;
            if ((int) $output->cycle_time_seconds > 0) {
                $aggregate['cycles'][] = (int) $output->cycle_time_seconds;
            }
            unset($aggregate);
        }

        return collect($buckets)->map(function (array $bucket, int $index) use ($machines, $aggregates): array {
            $measured = $machines->map(function (Machine $machine) use ($bucket, $index, $aggregates): array {
                $aggregate = $aggregates[$index][$machine->id];
                $cycles = $aggregate['cycles'];

                return $this->metrics(
                    $machine,
                    $bucket['start'],
                    $bucket['end'],
                    $this->scheduledMinutes($machine, $bucket['start'], $bucket['end']),
                    $aggregate['planned'],
                    $aggregate['unplanned'],
                    $aggregate['good'],
                    $aggregate['reject'],
                    $cycles === [] ? 0.0 : (float) (array_sum($cycles) / count($cycles)),
                );
            })->filter(fn (array $row): bool => ($row['diagnostics']['run_time'] ?? 0) > 0);
            $oee = $measured->pluck('oee')->filter(static fn ($value): bool => $value !== null);

            return [
                'date' => $bucket['start']->toDateString(),
                'oee' => $oee->isEmpty() ? null : round((float) $oee->avg(), 4),
            ];
        })->all();
    }

    /** @param array<int, array{start: Carbon, end: Carbon}> $buckets */
    private function bucketIndex(Carbon $timestamp, array $buckets): ?int
    {
        foreach ($buckets as $index => $bucket) {
            if ($timestamp->betweenIncluded($bucket['start'], $bucket['end'])) {
                return $index;
            }
        }

        return null;
    }

    private function metrics(
        Machine $machine,
        Carbon $from,
        Carbon $to,
        int $scheduledMinutes,
        int $planned,
        int $unplanned,
        int $good,
        int $reject,
        float $idealCycle,
    ): array {
        $total = $good + $reject;
        $availableTime = max(0, $scheduledMinutes - $planned);
        $runTime = max(0, $availableTime - $unplanned);
        $availability = $availableTime > 0 ? $runTime / $availableTime : null;
        $performanceRaw = $runTime > 0 && $total > 0 && $idealCycle > 0
            ? ($total * $idealCycle / 60.0) / $runTime
            : null;
        $performance = $performanceRaw === null ? null : min(1.0, max(0.0, $performanceRaw));
        $quality = $total > 0 ? $good / $total : null;
        $oee = $availability !== null && $performance !== null && $quality !== null
            ? $availability * $performance * $quality
            : null;

        return [
            'availability' => $availability === null ? null : round($availability, 4),
            'performance' => $performance === null ? null : round($performance, 4),
            'quality' => $quality === null ? null : round($quality, 4),
            'oee' => $oee === null ? null : round($oee, 4),
            'diagnostics' => [
                'scheduled_minutes' => $scheduledMinutes,
                'planned_downtime' => $planned,
                'unplanned_downtime' => $unplanned,
                'available_time' => $availableTime,
                'run_time' => $runTime,
                'good_count' => $good,
                'reject_count' => $reject,
                'ideal_cycle_seconds' => round($idealCycle, 2),
                'performance_capped' => $performanceRaw !== null && $performanceRaw > 1.0,
            ],
            'period_from' => $from->toIso8601String(),
            'period_to' => $to->toIso8601String(),
        ];
    }
}
