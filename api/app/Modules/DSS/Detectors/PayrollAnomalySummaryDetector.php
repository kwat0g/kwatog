<?php

declare(strict_types=1);

namespace App\Modules\DSS\Detectors;

use App\Modules\DSS\Contracts\DetectorEmission;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\DSS\Contracts\DetectorScanResult;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Support\Facades\DB;

/** D21 — aggregate unresolved anomaly flags by period; employee data stays in payroll workflows. */
class PayrollAnomalySummaryDetector implements DetectorInterface
{
    public function id(): string { return 'dss.payroll_anomaly_summary'; }
    public function domain(): string { return 'payroll'; }
    public function ownerPermissions(): array { return ['payroll.periods.view']; }
    public function version(): string { return 'v1'; }
    public function defaultParams(): array { return []; }

    public function detect(array $context = []): DetectorScanResult
    {
        $limit = max(1, (int) ($context['limit'] ?? 200));
        $rows = DB::table('payroll_anomaly_flags as flags')
            ->join('payroll_periods as periods', 'periods.id', '=', 'flags.payroll_period_id')
            ->where('flags.is_resolved', false)
            ->whereIn('periods.status', ['computed', 'approved'])
            ->select('periods.id as period_id', 'periods.period_start', 'periods.period_end', 'periods.is_first_half')
            ->selectRaw('COUNT(*) as flag_count')
            ->groupBy('periods.id', 'periods.period_start', 'periods.period_end', 'periods.is_first_half')
            ->orderBy('periods.period_start')
            ->limit($limit + 1)
            ->get();

        $emissions = $rows->take($limit)->map(static function ($row): DetectorEmission {
            return new DetectorEmission(
                type: 'payroll_anomaly_summary',
                severity: 'warning',
                dedupeKey: 'payroll-period:'.$row->period_id,
                rationale: [
                    'rule' => 'unresolved anomaly flags remain on a computed or approved payroll period',
                    'period_start' => (string) $row->period_start,
                    'period_end' => (string) $row->period_end,
                    'half' => $row->is_first_half ? 'first' : 'second',
                    'unresolved_flags' => (int) $row->flag_count,
                ],
                subjectType: PayrollPeriod::class,
                subjectId: (int) $row->period_id,
                confidence: 1.0,
                action: ['route' => '/payroll/periods', 'label' => 'Review payroll flags →'],
                ownerPermissions: ['payroll.periods.view'],
                ownerRoleSlugs: ['finance_officer', 'hr_officer'],
            );
        })->all();

        return new DetectorScanResult($emissions, (int) $rows->sum('flag_count'), $rows->count() <= $limit);
    }
}
