<?php

declare(strict_types=1);

namespace App\Modules\DSS\Detectors;

use App\Common\Services\SettingsService;
use App\Modules\DSS\Contracts\DetectorEmission;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\DSS\Contracts\DetectorScanResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** D22 — report certification expiry totals by course, visible only to HR record managers. */
class TrainingExpirySummaryDetector implements DetectorInterface
{
    public function __construct(private readonly SettingsService $settings) {}

    public function id(): string
    {
        return 'dss.training_expiry_summary';
    }

    public function domain(): string
    {
        return 'human_resources';
    }

    public function ownerPermissions(): array
    {
        return ['hr.employees.trainings.manage'];
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
        $today = today()->toDateString();
        $horizonDays = $this->settings->requiredInt('hr.training_expiry.t30_days', 1, 3650);
        $horizon = today()->addDays($horizonDays)->toDateString();
        $rows = DB::table('employee_trainings as et')
            ->join('trainings as t', 't.id', '=', 'et.training_id')
            ->whereIn('et.status', ['completed', 'expired'])
            ->whereNotNull('et.completed_at')
            ->whereNotNull('et.expires_at')
            ->where('et.expires_at', '<=', $horizon)
            ->whereNotExists(static function ($query): void {
                $query->selectRaw('1')->from('employee_trainings as newer')
                    ->whereColumn('newer.employee_id', 'et.employee_id')
                    ->whereColumn('newer.training_id', 'et.training_id')
                    ->whereIn('newer.status', ['completed', 'expired'])
                    ->where(function ($newer): void {
                        $newer->whereColumn('newer.completed_at', '>', 'et.completed_at')
                            ->orWhere(function ($tie): void {
                                $tie->whereColumn('newer.completed_at', 'et.completed_at')
                                    ->whereColumn('newer.id', '>', 'et.id');
                            });
                    });
            })
            ->select('et.training_id', 't.name as training_name')
            ->selectRaw('COUNT(*) as affected_count, MIN(et.expires_at) as next_expiry, SUM(CASE WHEN et.expires_at < ? THEN 1 ELSE 0 END) as expired_count', [$today])
            ->groupBy('et.training_id', 't.name')
            ->orderBy('next_expiry')
            ->limit($limit + 1)
            ->get();

        $emissions = $rows->take($limit)->map(static fn ($row): DetectorEmission => new DetectorEmission(
            type: 'training_expiry',
            severity: (int) $row->expired_count > 0 ? 'critical' : 'warning',
            dedupeKey: 'training-expiry:'.$row->training_id,
            rationale: [
                'rule' => 'current employee training certifications expire within the configured reminder horizon',
                'training' => $row->training_name,
                'affected_records' => (int) $row->affected_count,
                'already_expired' => (int) $row->expired_count,
                'next_expiry' => (string) $row->next_expiry,
                'horizon_days' => $horizonDays,
            ],
            subjectType: 'training',
            subjectId: (int) $row->training_id,
            confidence: 1.0,
            action: ['route' => '/hr/training/matrix', 'label' => 'Review training matrix →'],
            ownerPermissions: ['hr.employees.trainings.manage'],
            ownerRoleSlugs: ['hr_officer'],
            businessDueAt: Carbon::parse($row->next_expiry),
        ))->all();

        return new DetectorScanResult($emissions, (int) $rows->sum('affected_count'), $rows->count() <= $limit);
    }
}
