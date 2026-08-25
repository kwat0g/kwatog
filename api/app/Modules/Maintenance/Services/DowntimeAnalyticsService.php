<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Services;

use App\Common\Services\SettingsService;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Enums\MachineDowntimeCategory;
use App\Modules\Production\Models\MachineDowntime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ADV8 — Maintenance Automation.
 * Downtime analytics: MTBF, MTTR, breakdown frequency, category breakdowns,
 * and trend over time.
 */
class DowntimeAnalyticsService
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Overall summary for a machine or all machines.
     *
     * Downtime is treated as an interval. Every row is clipped to the requested
     * window, including open rows, so the summary cannot import time from
     * outside the report period.
     *
     * @return array{
     *   total_downtime_minutes: int,
     *   breakdown_count: int,
     *   mtbf_hours: float|null,
     *   mttr_minutes: float|null,
     *   availability_pct: float|null,
     *   category_breakdown: array<int, array{category: string, minutes: int, count: int}>,
     * }
     */
    public function summary(?int $machineId = null, ?Carbon $from = null, ?Carbon $to = null, ?string $search = null): array
    {
        $from = $from ?? now()->subDays($this->settings->requiredInt('maintenance.downtime.default_history_days', 1));
        $to = $to ?? now();
        $rows = $this->overlappingRows($machineId, $from, $to, $search);

        $totalMinutes = 0;
        $breakdownCount = 0;
        $breakdownMinutes = 0;
        $categories = [];

        foreach ($rows as $row) {
            $minutes = $this->clippedMinutes($row, $from, $to);
            if ($minutes <= 0) {
                continue;
            }

            $category = $this->categoryValue($row);
            $totalMinutes += $minutes;
            $categories[$category]['minutes'] = ($categories[$category]['minutes'] ?? 0) + $minutes;
            $categories[$category]['count'] = ($categories[$category]['count'] ?? 0) + 1;

            if ($category === MachineDowntimeCategory::Breakdown->value) {
                $breakdownCount++;
                $breakdownMinutes += $minutes;
            }
        }

        // MTBF = total uptime / number of breakdowns.
        $mtbf = null;
        $windowMinutes = max(0, (int) floor($from->diffInSeconds($to, true) / 60));
        if ($breakdownCount > 0) {
            $uptimeMinutes = max(0, $windowMinutes - $totalMinutes);
            $mtbf = round($uptimeMinutes / $breakdownCount / 60, 2);
        }

        // MTTR = total repair time / number of breakdowns.
        $mttr = $breakdownCount > 0 ? round($breakdownMinutes / $breakdownCount, 2) : null;
        $availabilityPct = $windowMinutes > 0
            ? round(max(0, $windowMinutes - $totalMinutes) / $windowMinutes * 100, 2)
            : null;

        uasort($categories, static fn (array $left, array $right): int => $right['minutes'] <=> $left['minutes']);
        $categoryBreakdown = [];
        foreach ($categories as $category => $values) {
            $categoryBreakdown[] = [
                'category' => $category,
                'minutes' => (int) $values['minutes'],
                'count' => (int) $values['count'],
            ];
        }

        return [
            'total_downtime_minutes' => $totalMinutes,
            'breakdown_count' => $breakdownCount,
            'mtbf_hours' => $mtbf,
            'mttr_minutes' => $mttr,
            'availability_pct' => $availabilityPct,
            'category_breakdown' => $categoryBreakdown,
        ];
    }

    /**
     * Daily downtime trend for charting. Intervals spanning midnight are split
     * across each affected day instead of being assigned to their start date.
     *
     * @return array<int, array{date: string, total_minutes: int, breakdown_minutes: int}>
     */
    public function dailyTrend(?int $machineId = null, ?int $days = null, ?string $search = null): array
    {
        $days ??= $this->historyDays();
        $from = now()->subDays($days)->startOfDay();
        $to = now()->endOfDay();
        $trend = [];

        foreach ($this->overlappingRows($machineId, $from, $to, $search) as $row) {
            $category = $this->categoryValue($row);
            $this->forEachDaySegment($row, $from, $to, function (string $date, int $minutes) use (&$trend, $category): void {
                $trend[$date]['total_minutes'] = ($trend[$date]['total_minutes'] ?? 0) + $minutes;
                if ($category === MachineDowntimeCategory::Breakdown->value) {
                    $trend[$date]['breakdown_minutes'] = ($trend[$date]['breakdown_minutes'] ?? 0) + $minutes;
                }
            });
        }

        ksort($trend);
        $result = [];
        foreach ($trend as $date => $values) {
            $result[] = [
                'date' => $date,
                'total_minutes' => (int) ($values['total_minutes'] ?? 0),
                'breakdown_minutes' => (int) ($values['breakdown_minutes'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Top offending machines by clipped downtime.
     *
     * @return array<int, array{machine_id: string, machine_code: string, name: string, downtime_minutes: int, breakdown_count: int}>
     */
    public function topMachines(int $limit = 10, ?int $days = null, ?string $search = null): array
    {
        $days ??= $this->historyDays();
        $from = now()->subDays($days)->startOfDay();
        $to = now()->endOfDay();
        $grouped = [];

        foreach ($this->overlappingRows(null, $from, $to, $search) as $row) {
            $minutes = $this->clippedMinutes($row, $from, $to);
            $machine = $row->machine;
            if ($minutes <= 0 || ! $machine) {
                continue;
            }

            $key = (string) $machine->id;
            $grouped[$key]['machine_id'] = $machine->hash_id;
            $grouped[$key]['machine_code'] = $machine->machine_code;
            $grouped[$key]['name'] = $machine->name;
            $grouped[$key]['downtime_minutes'] = ($grouped[$key]['downtime_minutes'] ?? 0) + $minutes;
            $grouped[$key]['breakdown_count'] ??= 0;
            if ($this->categoryValue($row) === MachineDowntimeCategory::Breakdown->value) {
                $grouped[$key]['breakdown_count'] = ($grouped[$key]['breakdown_count'] ?? 0) + 1;
            }
        }

        usort($grouped, static fn (array $left, array $right): int => $right['downtime_minutes'] <=> $left['downtime_minutes']);
        return array_slice(array_values($grouped), 0, max(1, $limit));
    }

    /**
     * Per-machine summary for the downtime dashboard.
     *
     * @return array<int, array{machine: array{id: string, code: string, name: string}, summary: array}>
     */
    public function allMachinesSummary(?int $days = null, ?string $search = null): array
    {
        $days ??= $this->historyDays();
        $from = now()->subDays($days)->startOfDay();
        $to = now()->endOfDay();

        $machines = Machine::query()
            ->when(trim((string) $search) !== '', function (Builder $query) use ($search): void {
                $term = '%'.trim((string) $search).'%';
                $query->where(function (Builder $machineQuery) use ($term): void {
                    $machineQuery
                        ->where('machine_code', 'ilike', $term)
                        ->orWhere('name', 'ilike', $term);
                });
            })
            ->orderBy('machine_code')
            ->get();

        return $machines->map(function (Machine $machine) use ($from, $to): array {
            return [
                'machine' => [
                    'id' => $machine->hash_id,
                    'code' => $machine->machine_code,
                    'name' => $machine->name,
                ],
                'summary' => $this->summary((int) $machine->id, $from, $to),
            ];
        })->values()->all();
    }

    /**
     * L-39 — Pareto of downtime by category. Sorted DESC by minutes with
     * a running cumulative percentage so the SPA can render the classic
     * Pareto bar+line chart (top categories vs cumulative contribution).
     *
     * @return array<int, array{
     *   category: string, label: string, minutes: int, count: int,
     *   percent: float, cumulative_percent: float
     * }>
     */
    public function categoryPareto(?int $machineId = null, ?int $days = null, ?string $search = null): array
    {
        $days ??= $this->historyDays();
        $from = now()->subDays($days)->startOfDay();
        $to = now()->endOfDay();
        $categories = [];

        foreach ($this->overlappingRows($machineId, $from, $to, $search) as $row) {
            $minutes = $this->clippedMinutes($row, $from, $to);
            if ($minutes <= 0) {
                continue;
            }

            $category = $this->categoryValue($row);
            $categories[$category]['minutes'] = ($categories[$category]['minutes'] ?? 0) + $minutes;
            $categories[$category]['count'] = ($categories[$category]['count'] ?? 0) + 1;
        }

        if ($categories === []) {
            return [];
        }

        uasort($categories, static fn (array $left, array $right): int => $right['minutes'] <=> $left['minutes']);
        $totalMinutes = array_sum(array_column($categories, 'minutes'));
        $running = 0;
        $result = [];

        foreach ($categories as $category => $values) {
            $minutes = (int) $values['minutes'];
            $running += $minutes;
            $result[] = [
                'category' => $category,
                'label' => $this->labelFor($category),
                'minutes' => $minutes,
                'count' => (int) $values['count'],
                'percent' => round($minutes / $totalMinutes * 100, 2),
                'cumulative_percent' => round($running / $totalMinutes * 100, 2),
            ];
        }

        return $result;
    }

    private function overlappingRows(?int $machineId, Carbon $from, Carbon $to, ?string $search): Collection
    {
        return MachineDowntime::query()
            ->with('machine:id,machine_code,name')
            ->where('start_time', '<', $to)
            ->where(function (Builder $query) use ($from): void {
                $query->whereNull('end_time')->orWhere('end_time', '>', $from);
            })
            ->when($machineId !== null, fn (Builder $query) => $query->where('machine_id', $machineId))
            ->when(trim((string) $search) !== '', function (Builder $query) use ($search): void {
                $term = '%'.trim((string) $search).'%';
                $query->whereHas('machine', function (Builder $machineQuery) use ($term): void {
                    $machineQuery
                        ->where('machine_code', 'ilike', $term)
                        ->orWhere('name', 'ilike', $term);
                });
            })
            ->get();
    }

    private function clippedMinutes(MachineDowntime $row, Carbon $from, Carbon $to): int
    {
        $start = $row->start_time instanceof Carbon ? $row->start_time->copy() : Carbon::parse($row->start_time);
        $end = $row->end_time instanceof Carbon ? $row->end_time->copy() : ($row->end_time ? Carbon::parse($row->end_time) : $to->copy());
        $start = $start->greaterThan($from) ? $start : $from->copy();
        $end = $end->lessThan($to) ? $end : $to->copy();

        return $end->greaterThan($start)
            ? max(0, (int) floor($start->diffInSeconds($end, true) / 60))
            : 0;
    }

    /** @param callable(string, int): void $consumer */
    private function forEachDaySegment(MachineDowntime $row, Carbon $from, Carbon $to, callable $consumer): void
    {
        $start = $row->start_time instanceof Carbon ? $row->start_time->copy() : Carbon::parse($row->start_time);
        $end = $row->end_time instanceof Carbon ? $row->end_time->copy() : ($row->end_time ? Carbon::parse($row->end_time) : $to->copy());
        $start = $start->greaterThan($from) ? $start : $from->copy();
        $end = $end->lessThan($to) ? $end : $to->copy();

        while ($start->lessThan($end)) {
            $dayEnd = $start->copy()->startOfDay()->addDay();
            $segmentEnd = $end->lessThan($dayEnd) ? $end->copy() : $dayEnd;
            $minutes = max(0, (int) floor($start->diffInSeconds($segmentEnd, true) / 60));
            if ($minutes > 0) {
                $consumer($start->toDateString(), $minutes);
            }
            $start = $segmentEnd;
        }
    }

    private function categoryValue(MachineDowntime $row): string
    {
        return $row->category instanceof MachineDowntimeCategory
            ? $row->category->value
            : (string) $row->category;
    }

    private function labelFor(string $category): string
    {
        return match ($category) {
            'breakdown' => 'Breakdown',
            'changeover' => 'Changeover',
            'material_shortage' => 'Material Shortage',
            'no_order' => 'No Order',
            'planned_maintenance' => 'Planned Maintenance',
            default => ucwords(str_replace('_', ' ', $category)),
        };
    }

    private function historyDays(): int
    {
        return $this->settings->requiredInt('maintenance.downtime.default_history_days', 1, 3650);
    }
}
