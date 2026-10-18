<?php

declare(strict_types=1);

namespace App\Modules\DSS\Detectors;

use App\Modules\DSS\Contracts\DetectorEmission;
use App\Modules\DSS\Contracts\DetectorInterface;
use App\Modules\Payroll\Enums\PayrollAnomalyType;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollAnomalyFlag;
use App\Modules\Payroll\Models\PayrollPeriod;

/**
 * D21 — pre-payroll checklist. Reuses PayrollAnomalyService's
 * PayrollAnomalyFlag rows: the same gate PayrollPeriodService::finalize
 * blocks on. Adds what the period page lacks: an aggregated,
 * per-period checklist of what still needs review before finalize.
 *
 * One emission per open period (computed/approved) that still has unresolved
 * flags — the surfaces a reviewer can still act on. Locked or closed periods
 * are out, resolved flags are out. Row sees a clean period? Nothing emitted.
 *
 * Severity: critical only when a zero-pay flag is still unresolved — money
 * errors on a run about to leave the bank; every other unresolved set is a
 * warning. `open_attendance` never blocks finalize, so it is counted but
 * excluded from blocking_count. Rows labeled audience hr/finance; per-row
 * enforcement lives in RecommendationService. Deterministic counts →
 * confidence 1.0. Read-only.
 */
class PrePayrollChecklistDetector implements DetectorInterface
{
    public const ID = 'dss.prepayroll_checklist';

    public function id(): string
    {
        return self::ID;
    }

    public function domain(): string
    {
        return 'payroll';
    }

    public function ownerPermissions(): array
    {
        // Reachability only. Row-level access is enforced per audience_roles
        // in RecommendationService.
        return ['dss.view'];
    }

    public function version(): string
    {
        return 'v1';
    }

    public function defaultParams(): array
    {
        return ['max_evidence_per_period' => 5];
    }

    /** @return list<DetectorEmission> */
    public function detect(array $context = []): array
    {
        $maxEvidence = max(1, (int) ($context['max_evidence'] ?? $this->defaultParams()['max_evidence_per_period']));

        $flags = PayrollAnomalyFlag::query()
            ->where('is_resolved', false)
            ->whereHas('period', static fn ($q) => $q->whereIn('status', [
                PayrollPeriodStatus::Computed->value,
                PayrollPeriodStatus::Approved->value,
            ]))
            ->with(['employee:id,first_name,last_name', 'period'])
            ->orderBy('payroll_period_id')
            ->get();

        if ($flags->isEmpty()) {
            return [];
        }

        $byPeriod = $flags->groupBy('payroll_period_id');

        $emissions = [];

        /** @var PayrollPeriod $period */
        foreach ($byPeriod as $period) {
            $byType = $period->groupBy('flag_type.value');
            $blocking = $period->reject(
                static fn (PayrollAnomalyFlag $f): bool => $f->flag_type === PayrollAnomalyType::OpenAttendance,
            );

            $evidence = $period->groupBy('employee_id')
                ->map(static fn ($rows): string => sprintf(
                    '%s — %d flag(s) (%s)',
                    trim($rows->first()->employee->first_name.' '.$rows->first()->employee->last_name),
                    $rows->count(),
                    $rows->pluck('flag_type')->unique()->sort()->implode(', '),
                ))
                ->take($maxEvidence)
                ->values()
                ->all();

            $emissions[] = new DetectorEmission(
                type: 'prepayroll_checklist',
                severity: isset($byType[PayrollAnomalyType::ZeroPay->value]) ? 'critical' : 'warning',
                dedupeKey: "prepayroll:{$period->first()->payroll_period_id}",
                rationale: [
                    'rule' => 'pre-payroll anomaly review',
                    'period' => sprintf(
                        '%s → %s (%s half)',
                        $period->first()->period->period_start?->toDateString(),
                        $period->first()->period->period_end?->toDateString(),
                        $period->first()->period->is_first_half ? '1st' : '2nd',
                    ),
                    'unresolved_total' => $period->count(),
                    'blocking_count' => $blocking->count(),
                    'by_type' => array_map('count', $byType->all()),
                    'capped_at_max_evidence' => $period->groupBy('employee_id')->count() > $maxEvidence,
                    'audience_roles' => ['hr_officer', 'finance_officer'],
                    'evidence' => $evidence,
                    'why_it_matters' => 'Unresolved anomaly flags are the gate payroll finalization blocks on; zero pay on an outgoing run is money risk.',
                ],
                confidence: 1.0,
                action: ['route' => '/payroll/periods', 'label' => 'Open payroll periods →'],
            );
        }

        return $emissions;
    }
}
