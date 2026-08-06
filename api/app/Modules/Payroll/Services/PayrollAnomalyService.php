<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Payroll\Enums\PayrollAnomalyType;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollAnomalyFlag;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Task A9 — Detect anomalies in a freshly computed payroll period.
 *
 * Rules (all percentages are versus the employee's previous (non-13th-month)
 * payroll, regardless of which period it came from):
 *
 *   - Net pay change above the configured ratio → large_change
 *   - OT hours above the configured threshold  → excessive_ot
 *   - Deductions above the configured ratio     → high_deduction
 *   - No previous payroll exists  → first_payroll
 *   - Net pay = 0                 → zero_pay
 *
 * Idempotent on (payroll_id, flag_type) via the unique index.
 */
class PayrollAnomalyService
{
    /** @var array{net_change_ratio:float,overtime_hours:float,deduction_ratio:float}|null */
    private ?array $policy = null;

    public function __construct(private readonly SettingsService $settings) {}

    public function detect(PayrollPeriod $period): int
    {
        $created = 0;

        $payrolls = Payroll::query()
            ->where('payroll_period_id', $period->id)
            ->get();

        // Drop flags whose payroll row no longer exists. A recompute deletes and
        // recreates every row, and payroll_anomaly_flags cascades on payroll_id,
        // so orphans are normally impossible — but flags raised against rows that
        // were skipped this run would otherwise linger and block finalize()
        // forever with no way to reach them from the UI.
        PayrollAnomalyFlag::query()
            ->where('payroll_period_id', $period->id)
            ->whereNotIn('payroll_id', $payrolls->pluck('id'))
            ->delete();

        foreach ($payrolls as $payroll) {
            $created += $this->detectForPayroll($payroll, $period);
        }

        return $created;
    }

    private function detectForPayroll(Payroll $payroll, PayrollPeriod $period): int
    {
        $policy = $this->policy();
        $created = 0;
        $employeeId = (int) $payroll->employee_id;

        // Compare against the employee's most recent payroll from a period of
        // the SAME half. Two fixes here:
        //
        //  - Order by the period's actual dates, not by payroll id. Recomputing
        //    a period deletes and recreates its rows with fresh (higher) ids, so
        //    id order stopped tracking chronology and a period could end up
        //    compared against a LATER one.
        //  - Match is_first_half. Government deductions are withheld on the
        //    first half only (PH semi-monthly convention), so first-vs-second
        //    half net pay always differs by roughly the whole gov contribution.
        //    Comparing across halves flagged 113 of 201 employees on a single
        //    period as "large change" — and because finalize() blocks on
        //    unresolved flags, HR had to hand-clear every one of them.
        $previous = Payroll::query()
            ->where('payrolls.employee_id', $employeeId)
            ->where('payrolls.id', '!=', $payroll->id)
            ->whereHas('period', fn ($q) => $q
                ->where('is_thirteenth_month', false)
                ->where('is_first_half', (bool) $period->is_first_half)
                ->where('period_start', '<', $period->period_start))
            ->join('payroll_periods', 'payroll_periods.id', '=', 'payrolls.payroll_period_id')
            ->orderByDesc('payroll_periods.period_start')
            ->select('payrolls.*')
            ->first();

        $current = (float) $payroll->net_pay;
        $gross   = (float) $payroll->gross_pay;
        // `payrolls` has no overtime_hours column — the old read of
        // $payroll->overtime_hours was always null, so excessive_ot could never
        // fire. OT hours live on the attendance rows, so sum them for the
        // period. Cheap: one aggregate per payroll row, indexed on
        // (employee_id, date).
        $ot = (float) Attendance::query()
            ->where('employee_id', $employeeId)
            ->whereBetween('date', [$period->period_start, $period->period_end])
            ->sum('overtime_hours');

        // 1. Zero pay
        if ($current === 0.0) {
            $created += $this->flag($payroll, $period, PayrollAnomalyType::ZeroPay, [
                'current_net'      => $current,
            ]);
        }

        // 2. First payroll
        if (! $previous) {
            $created += $this->flag($payroll, $period, PayrollAnomalyType::FirstPayroll, [
                'current_net' => $current,
            ]);
        } else {
            $prev = (float) $previous->net_pay;
            if ($prev > 0.0) {
                $delta = $current - $prev;
                $pct   = abs($delta) / max(0.01, $prev);
                if ($pct > $policy['net_change_ratio']) {
                    $created += $this->flag($payroll, $period, PayrollAnomalyType::LargeChange, [
                        'previous_net'   => $prev,
                        'current_net'    => $current,
                        'percent_change' => round($pct * 100, 2),
                        'direction'      => $delta >= 0 ? 'increase' : 'decrease',
                    ]);
                }
            }
        }

        // 3. Excessive OT
        if ($ot > $policy['overtime_hours']) {
            $created += $this->flag($payroll, $period, PayrollAnomalyType::ExcessiveOt, [
                'overtime_hours' => $ot,
            ]);
        }

        // 4. High deduction ratio. Measure actual deductions, not (gross - net):
        // the latter also swept in signed adjustments, so a negative adjustment
        // inflated the ratio and a positive one masked a genuinely heavy
        // deduction load.
        if ($gross > 0) {
            $deductions = (float) $payroll->total_deductions;
            $ratio = $deductions / $gross;
            if ($ratio > $policy['deduction_ratio']) {
                $created += $this->flag($payroll, $period, PayrollAnomalyType::HighDeduction, [
                    'gross_pay'        => $gross,
                    'net_pay'          => $current,
                    'deduction_ratio'  => round($ratio, 4),
                ]);
            }
        }

        return $created;
    }

    private function flag(Payroll $payroll, PayrollPeriod $period, PayrollAnomalyType $type, array $details): int
    {
        try {
            // Use raw insert with ignore-on-conflict semantics via firstOrCreate.
            $row = PayrollAnomalyFlag::firstOrCreate([
                'payroll_id' => $payroll->id,
                'flag_type'  => $type->value,
            ], [
                'payroll_period_id' => $period->id,
                'employee_id'       => $payroll->employee_id,
                'details'           => $details,
                'is_resolved'       => false,
            ]);
            return $row->wasRecentlyCreated ? 1 : 0;
        } catch (\Throwable $e) {
            Log::warning('PayrollAnomalyService: flag failed', [
                'payroll_id' => $payroll->id,
                'type'       => $type->value,
                'error'      => $e->getMessage(),
            ]);
            return 0;
        }
    }

    public function resolve(PayrollAnomalyFlag $flag, int $userId, ?string $remarks): PayrollAnomalyFlag
    {
        return DB::transaction(function () use ($flag, $userId, $remarks) {
            $flag->update([
                'is_resolved'        => true,
                'resolved_by'        => $userId,
                'resolved_at'        => now(),
                'resolution_remarks' => $remarks,
            ]);
            return $flag->fresh();
        });
    }

    public function unresolvedCount(int $periodId): int
    {
        return PayrollAnomalyFlag::where('payroll_period_id', $periodId)
            ->where('is_resolved', false)
            ->count();
    }

    /** @return array{net_change_ratio:float,overtime_hours:float,deduction_ratio:float} */
    private function policy(): array
    {
        if ($this->policy !== null) {
            return $this->policy;
        }

        $policy = [
            'net_change_ratio' => $this->numberSetting('payroll.anomaly.net_change_ratio'),
            'overtime_hours' => $this->numberSetting('payroll.anomaly.overtime_hours'),
            'deduction_ratio' => $this->numberSetting('payroll.anomaly.deduction_ratio'),
        ];
        if ($policy['net_change_ratio'] < 0 || $policy['deduction_ratio'] < 0 || $policy['deduction_ratio'] > 1 || $policy['overtime_hours'] < 0) {
            throw new BusinessRuleException('Payroll anomaly policy settings are invalid.');
        }

        return $this->policy = $policy;
    }

    private function numberSetting(string $key): float
    {
        $value = $this->settings->get($key, '__missing_payroll_policy__');
        if (! is_numeric($value)) {
            throw new BusinessRuleException("Required payroll setting {$key} is not configured.");
        }

        return (float) $value;
    }
}
