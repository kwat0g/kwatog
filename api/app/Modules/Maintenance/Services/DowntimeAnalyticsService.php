<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Services;

use App\Modules\Production\Enums\MachineDowntimeCategory;
use App\Modules\Production\Models\MachineDowntime;
use App\Modules\MRP\Models\Machine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ADV8 — Maintenance Automation.
 * Downtime analytics: MTBF, MTTR, breakdown frequency, category breakdowns,
 * and trend over time.
 */
class DowntimeAnalyticsService
{
    /**
     * Overall summary for a machine or all machines.
     *
     * @return array{
     *   total_downtime_minutes: int,
     *   breakdown_count: int,
     *   mtbf_hours: float|null,
     *   mttr_minutes: float|null,
     *   availability_pct: float,
     *   category_breakdown: array<int, array{category: string, minutes: int, count: int}>,
     * }
     */
    public function summary(?int $machineId = null, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $from = $from ?? now()->subDays(30);
        $to   = $to   ?? now();

        $base = MachineDowntime::query()
            ->whereBetween('start_time', [$from, $to])
            ->when($machineId, fn ($q) => $q->where('machine_id', $machineId));

        $totalMinutes = (int) $base->clone()->sum('duration_minutes');
        $breakdownCount = (int) $base->clone()->where('category', MachineDowntimeCategory::Breakdown->value)->count();

        // MTBF = total uptime / number of breakdowns
        $mtbf = null;
        if ($breakdownCount > 0) {
            $windowMinutes = $to->diffInMinutes($from);
            $uptimeMinutes = max(0, $windowMinutes - $totalMinutes);
            $mtbf = round($uptimeMinutes / $breakdownCount / 60, 2); // hours
        }

        // MTTR = total repair time / number of breakdowns
        $mttr = null;
        $breakdownMinutes = (int) $base->clone()
            ->where('category', MachineDowntimeCategory::Breakdown->value)
            ->sum('duration_minutes');
        if ($breakdownCount > 0) {
            $mttr = round($breakdownMinutes / $breakdownCount, 2);
        }

        // Availability = uptime / total time
        $windowMinutes = $to->diffInMinutes($from);
        $availabilityPct = $windowMinutes > 0
            ? round(max(0, $windowMinutes - $totalMinutes) / $windowMinutes * 100, 2)
            : 100.0;

        // Category breakdown
        $categoryBreakdown = $base->clone()
            ->select('category', DB::raw('COALESCE(SUM(duration_minutes),0) as minutes'), DB::raw('COUNT(*) as cnt'))
            ->groupBy('category')
            ->orderByDesc('minutes')
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category,
                'minutes'  => (int) $row->minutes,
                'count'    => (int) $row->cnt,
            ])
            ->toArray();

        return [
            'total_downtime_minutes' => $totalMinutes,
            'breakdown_count'        => $breakdownCount,
            'mtbf_hours'             => $mtbf,
            'mttr_minutes'           => $mttr,
            'availability_pct'       => $availabilityPct,
            'category_breakdown'     => $categoryBreakdown,
        ];
    }

    /**
     * Daily downtime trend for charting.
     *
     * @return array<int, array{date: string, total_minutes: int, breakdown_minutes: int}>
     */
    public function dailyTrend(?int $machineId = null, int $days = 30): array
    {
        $from = now()->subDays($days)->startOfDay();
        $to   = now()->endOfDay();

        // NOTE: DATE(start_time) is used for MySQL compatibility. For PostgreSQL,
        // date_trunc('day', start_time) would also work. This project uses PostgreSQL.
        $rows = DB::table('machine_downtimes')
            ->select(
                DB::raw('DATE(start_time) as date'),
                DB::raw('COALESCE(SUM(duration_minutes),0) as total_minutes'),
                DB::raw("COALESCE(SUM(CASE WHEN category = 'breakdown' THEN duration_minutes ELSE 0 END),0) as breakdown_minutes")
            )
            ->whereBetween('start_time', [$from, $to])
            ->when($machineId, fn ($q) => $q->where('machine_id', $machineId))
            ->groupBy(DB::raw('DATE(start_time)'))
            ->orderBy('date')
            ->get();

        return $rows->map(fn ($row) => [
            'date'               => $row->date,
            'total_minutes'      => (int) $row->total_minutes,
            'breakdown_minutes'  => (int) $row->breakdown_minutes,
        ])->toArray();
    }

    /**
     * Top offending machines by downtime.
     *
     * @return array<int, array{machine_id: int, machine_code: string, name: string, downtime_minutes: int, breakdown_count: int}>
     */
    public function topMachines(int $limit = 10, int $days = 30): array
    {
        $from = now()->subDays($days)->startOfDay();
        $to   = now()->endOfDay();

        return DB::table('machine_downtimes as md')
            ->join('machines as m', 'm.id', '=', 'md.machine_id')
            ->select(
                'm.id as machine_id',
                'm.machine_code',
                'm.name',
                DB::raw('COALESCE(SUM(md.duration_minutes),0) as downtime_minutes'),
                DB::raw("COUNT(CASE WHEN md.category = 'breakdown' THEN 1 END) as breakdown_count")
            )
            ->whereBetween('md.start_time', [$from, $to])
            ->groupBy('m.id', 'm.machine_code', 'm.name')
            ->orderByDesc('downtime_minutes')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'machine_id'        => (int) $row->machine_id,
                'machine_code'      => $row->machine_code,
                'name'              => $row->name,
                'downtime_minutes'  => (int) $row->downtime_minutes,
                'breakdown_count'   => (int) $row->breakdown_count,
            ])
            ->toArray();
    }

    /**
     * Per-machine summary for the downtime dashboard.
     *
     * @return array<int, array{machine: array{id: int, code: string, name: string}, summary: array}>
     */
    public function allMachinesSummary(int $days = 30): array
    {
        $machines = Machine::query()
            ->orderBy('machine_code')
            ->get();

        $from = now()->subDays($days)->startOfDay();
        $to   = now()->endOfDay();

        return $machines->map(function (Machine $machine) use ($from, $to) {
            return [
                'machine' => [
                    'id'    => $machine->id,
                    'code'  => $machine->machine_code,
                    'name'  => $machine->name,
                ],
                'summary' => $this->summary((int) $machine->id, $from, $to),
            ];
        })->toArray();
    }
}
