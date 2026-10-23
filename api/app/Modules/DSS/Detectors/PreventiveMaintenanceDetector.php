<?php

declare(strict_types=1);

namespace App\Modules\DSS\Detectors;

use App\Modules\DSS\Contracts\DetectorEmission;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\DSS\Contracts\DetectorScanResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** D18 — summarize overdue preventive work orders using the maintenance queue's existing definition. */
class PreventiveMaintenanceDetector implements DetectorInterface
{
    public function id(): string
    {
        return 'dss.preventive_maintenance_overdue';
    }

    public function domain(): string
    {
        return 'maintenance';
    }

    public function ownerPermissions(): array
    {
        return ['maintenance.view'];
    }

    public function version(): string
    {
        return 'v1';
    }

    public function defaultParams(): array
    {
        return [];
    }

    public function detect(array $context = []): DetectorScanResult
    {
        $limit = max(1, (int) ($context['limit'] ?? 200));
        $rows = DB::table('maintenance_work_orders as mwo')
            ->join('maintenance_schedules as schedule', 'schedule.id', '=', 'mwo.schedule_id')
            ->whereNull('schedule.deleted_at')
            ->where('mwo.type', 'preventive')
            ->whereNotIn('mwo.status', ['completed', 'cancelled'])
            ->where('schedule.next_due_at', '<', now())
            ->select('mwo.maintainable_type')
            ->selectRaw('COUNT(*) as overdue_count, MIN(schedule.next_due_at) as oldest_due_at')
            ->groupBy('mwo.maintainable_type')
            ->orderBy('oldest_due_at')
            ->limit($limit + 1)
            ->get();

        $emissions = $rows->take($limit)->map(static fn ($row): DetectorEmission => new DetectorEmission(
            type: 'preventive_maintenance_overdue',
            severity: 'warning',
            dedupeKey: 'preventive-maintenance:'.$row->maintainable_type,
            rationale: [
                'rule' => 'open preventive work orders passed their scheduled due date',
                'maintainable_type' => $row->maintainable_type,
                'overdue_count' => (int) $row->overdue_count,
                'oldest_due_at' => (string) $row->oldest_due_at,
            ],
            confidence: 1.0,
            action: ['route' => '/maintenance/work-orders?queue=overdue_preventive', 'label' => 'Review overdue preventive work →'],
            ownerPermissions: ['maintenance.view'],
            ownerRoleSlugs: ['maintenance_supervisor'],
            businessDueAt: Carbon::parse($row->oldest_due_at),
        ))->all();

        return new DetectorScanResult($emissions, (int) $rows->sum('overdue_count'), $rows->count() <= $limit);
    }
}
