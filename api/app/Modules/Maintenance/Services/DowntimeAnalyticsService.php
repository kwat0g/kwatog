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
        $segments = $this->effectiveSegments($rows, $from, $to);
        $totalSeconds = 0;
        $categorySeconds = [];
        foreach ($segments as $segment) {
            $seconds = $segment['end'] - $segment['start'];
            $totalSeconds += $seconds;
            $categorySeconds[$segment['category']] = ($categorySeconds[$segment['category']] ?? 0) + $seconds;
        }
        $totalMinutes = intdiv($totalSeconds, 60);
        $breakdownCount = $this->eventCount($rows, MachineDowntimeCategory::Breakdown->value, $from, $to);
        $breakdownRows = $rows->filter(fn (MachineDowntime $row): bool =>
            $this->categoryValue($row) === MachineDowntimeCategory::Breakdown->value
            && $this->startsInWindow($row, $from, $to));
        $breakdownSeconds = array_sum(array_map(
            static fn (array $segment): int => $segment['end'] - $segment['start'],
            $this->effectiveSegments($breakdownRows, $from, $to),
        ));
        $breakdownMinutes = intdiv($breakdownSeconds, 60);
        $categories = [];
        foreach ($rows as $row) {
            $category = $this->categoryValue($row);
            $categories[$category]['count'] = $this->eventCount($rows, $category, $from, $to);
        }
        foreach ($categories as $category => &$values) {
            $values['minutes'] = intdiv($categorySeconds[$category] ?? 0, 60);
        }
        unset($values);

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

        foreach ($this->effectiveSegments($this->overlappingRows($machineId, $from, $to, $search), $from, $to) as $segment) {
            $start = Carbon::createFromTimestamp($segment['start']);
            $end = Carbon::createFromTimestamp($segment['end']);
            while ($start->lessThan($end)) {
                $dayEnd = $start->copy()->startOfDay()->addDay();
                $segmentEnd = $end->lessThan($dayEnd) ? $end->copy() : $dayEnd;
                $seconds = $start->diffInSeconds($segmentEnd, true);
                $date = $start->toDateString();
                $trend[$date]['total_seconds'] = ($trend[$date]['total_seconds'] ?? 0) + $seconds;
                if ($segment['category'] === MachineDowntimeCategory::Breakdown->value) {
                    $trend[$date]['breakdown_seconds'] = ($trend[$date]['breakdown_seconds'] ?? 0) + $seconds;
                }
                $start = $segmentEnd;
            }
        }

        ksort($trend);
        $result = [];
        foreach ($trend as $date => $values) {
            $result[] = [
                'date' => $date,
                'total_minutes' => intdiv((int) ($values['total_seconds'] ?? 0), 60),
                'breakdown_minutes' => intdiv((int) ($values['breakdown_seconds'] ?? 0), 60),
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
        $rows = $this->overlappingRows(null, $from, $to, $search);

        foreach ($rows as $row) {
            $machine = $row->machine;
            if (! $machine) {
                continue;
            }

            $key = (string) $machine->id;
            $grouped[$key]['machine_id'] = $machine->hash_id;
            $grouped[$key]['machine_code'] = $machine->machine_code;
            $grouped[$key]['name'] = $machine->name;
            $grouped[$key]['breakdown_count'] = $this->eventCount(
                $rows->where('machine_id', $machine->id),
                MachineDowntimeCategory::Breakdown->value,
                $from,
                $to,
            );
        }

        foreach ($this->effectiveSegments($rows, $from, $to) as $segment) {
            $key = (string) $segment['machine_id'];
            $grouped[$key]['downtime_seconds'] = ($grouped[$key]['downtime_seconds'] ?? 0)
                + $segment['end'] - $segment['start'];
        }
        foreach ($grouped as &$machine) {
            $machine['downtime_minutes'] = intdiv($machine['downtime_seconds'] ?? 0, 60);
            unset($machine['downtime_seconds']);
        }
        unset($machine);

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
        $rows = $this->overlappingRows($machineId, $from, $to, $search);

        foreach ($rows as $row) {
            $category = $this->categoryValue($row);
            $categories[$category]['count'] = $this->eventCount($rows, $category, $from, $to);
        }
        foreach ($this->effectiveSegments($rows, $from, $to) as $segment) {
            $categories[$segment['category']]['seconds'] = ($categories[$segment['category']]['seconds'] ?? 0)
                + $segment['end'] - $segment['start'];
        }
        foreach ($categories as &$category) {
            $category['minutes'] = intdiv($category['seconds'] ?? 0, 60);
            unset($category['seconds']);
        }
        unset($category);

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

    /**
     * Return disjoint intervals per machine; overlapping causes are attributed
     * to the most severe active category so totals never count the same minute twice.
     *
     * @return list<array{machine_id:int,category:string,start:int,end:int}>
     */
    private function effectiveSegments(Collection $rows, Carbon $from, Carbon $to): array
    {
        $byMachine = [];
        foreach ($rows as $row) {
            $start = max($from->getTimestamp(), Carbon::parse($row->start_time)->getTimestamp());
            $end = min(
                $to->getTimestamp(),
                $row->end_time ? Carbon::parse($row->end_time)->getTimestamp() : $to->getTimestamp(),
            );
            if ($end > $start) {
                $byMachine[(int) $row->machine_id][] = [
                    'start' => $start,
                    'end' => $end,
                    'category' => $this->categoryValue($row),
                ];
            }
        }

        $priority = [
            MachineDowntimeCategory::Breakdown->value => 5,
            MachineDowntimeCategory::PlannedMaintenance->value => 4,
            MachineDowntimeCategory::Changeover->value => 3,
            MachineDowntimeCategory::MaterialShortage->value => 2,
            MachineDowntimeCategory::NoOrder->value => 1,
        ];
        $segments = [];

        // ponytail: O(n²) sweep over rows clipped to the report window; use an
        // interval tree only if measured downtime volume makes this slow.
        foreach ($byMachine as $machineId => $intervals) {
            $boundaries = array_values(array_unique(array_merge(
                array_column($intervals, 'start'),
                array_column($intervals, 'end'),
            )));
            sort($boundaries, SORT_NUMERIC);

            for ($index = 0, $last = count($boundaries) - 1; $index < $last; $index++) {
                $start = $boundaries[$index];
                $end = $boundaries[$index + 1];
                $active = array_filter($intervals, static fn (array $interval): bool =>
                    $interval['start'] < $end && $interval['end'] > $start);
                if ($active === []) {
                    continue;
                }

                usort($active, static fn (array $left, array $right): int =>
                    ($priority[$right['category']] ?? 0) <=> ($priority[$left['category']] ?? 0));
                $segments[] = [
                    'machine_id' => $machineId,
                    'category' => $active[0]['category'],
                    'start' => $start,
                    'end' => $end,
                ];
            }
        }

        return $segments;
    }

    private function eventCount(Collection $rows, string $category, Carbon $from, Carbon $to): int
    {
        $events = [];
        foreach ($rows as $row) {
            if ($this->categoryValue($row) === $category && $this->startsInWindow($row, $from, $to)) {
                $events[(int) $row->machine_id.'|'.$row->start_time->toISOString()] = true;
            }
        }

        return count($events);
    }

    private function startsInWindow(MachineDowntime $row, Carbon $from, Carbon $to): bool
    {
        return $row->start_time->greaterThanOrEqualTo($from) && $row->start_time->lessThan($to);
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
