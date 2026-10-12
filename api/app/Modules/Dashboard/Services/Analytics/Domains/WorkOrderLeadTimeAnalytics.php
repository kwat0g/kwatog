<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services\Analytics\Domains;

use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Support\Analytics\AnalyticsPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `production.wo_lead_time` — how long completed work orders actually take,
 * month by month.
 *
 * Phase 0 listed this as an unserved decision ("can we meet this date?") and an
 * index gap (`work_orders.actual_start` / `.actual_end` unindexed, only
 * `planned_start` was). The aggregation is a single `date_trunc` GROUP BY; the
 * monthly average is weighted in SQL (`AVG` over hours), and the overall average
 * is combined from those aggregates rather than by re-reading rows.
 *
 * Only completed orders (both timestamps set) count. An open order is not a
 * zero-hour order, and the tile says so in its caveats.
 */
final class WorkOrderLeadTimeAnalytics extends DomainMetric
{
    public function code(): string
    {
        return 'production.wo_lead_time';
    }

    protected function build(User $user, AnalyticsPeriod $period, array $filters): array
    {
        if (! Schema::hasTable('work_orders')) {
            return $this->emptyShape($filters);
        }

        $machineId = isset($filters['machine_id']) && (int) $filters['machine_id'] > 0
            ? (int) $filters['machine_id']
            : null;

        $rows = DB::table('work_orders')
            ->selectRaw(
                "to_char(date_trunc('month', actual_end), 'YYYY-MM') AS bucket, "
                .'AVG(EXTRACT(EPOCH FROM (actual_end - actual_start)) / 3600.0) AS avg_hours, '
                .'COUNT(*) AS completed'
            )
            ->whereNotNull('actual_start')
            ->whereNotNull('actual_end')
            ->whereBetween('actual_end', [$period->from, $period->to])
            ->when($machineId !== null, static fn ($query) => $query->where('machine_id', $machineId))
            ->groupByRaw("date_trunc('month', actual_end)")
            ->orderByRaw("date_trunc('month', actual_end)")
            ->get();

        $points = [];
        $completed = 0;
        $weightedHours = 0.0;

        foreach ($rows as $row) {
            $count = (int) $row->completed;
            $average = (float) $row->avg_hours;

            $completed += $count;
            $weightedHours += $average * $count;

            $points[] = [
                'label' => Carbon::createFromFormat('Y-m', (string) $row->bucket)->format('M Y'),
                'value' => round($average, 1),
            ];
        }

        return [
            'unit' => 'hours',
            'points' => $points,
            'completed' => $completed,
            'avg_hours' => $completed === 0 ? null : round($weightedHours / $completed, 1),
            'min_hours' => null,
            'target_hours' => null, // no target exists in settings — stated, not invented
            'machine_id' => $machineId,
        ];
    }

    /** @param array<string, mixed> $filters */
    private function emptyShape(array $filters): array
    {
        return [
            'unit' => 'hours',
            'points' => [],
            'completed' => 0,
            'avg_hours' => null,
            'min_hours' => null,
            'target_hours' => null,
            'machine_id' => isset($filters['machine_id']) ? (int) $filters['machine_id'] : null,
        ];
    }
}
