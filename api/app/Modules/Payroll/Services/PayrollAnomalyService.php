<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

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
 *   - Net pay change > 30%        → large_change
 *   - OT hours > 80               → excessive_ot
 *   - Deductions > 50% of gross   → high_deduction
 *   - No previous payroll exists  → first_payroll
 *   - Net pay = 0                 → zero_pay
 *
 * Idempotent on (payroll_id, flag_type) via the unique index.
 */
class PayrollAnomalyService
{
    private const NET_CHANGE_THRESHOLD = 0.30;
    private const OT_THRESHOLD_HOURS   = 80.0;
    private const DEDUCTION_RATIO      = 0.50;

    public function detect(PayrollPeriod $period): int
    {
        $created = 0;

        $payrolls = Payroll::query()
            ->where('payroll_period_id', $period->id)
            ->get();

        foreach ($payrolls as $payroll) {
            $created += $this->detectForPayroll($payroll, $period);
        }

        return $created;
    }

    private function detectForPayroll(Payroll $payroll, PayrollPeriod $period): int
    {
        $created = 0;
        $employeeId = (int) $payroll->employee_id;

        $previous = Payroll::query()
            ->where('employee_id', $employeeId)
            ->where('id', '!=', $payroll->id)
            ->whereHas('period', fn ($q) => $q->where('is_thirteenth_month', false))
            ->orderByDesc('id')
            ->first();

        $current = (float) $payroll->net_pay;
        $gross   = (float) $payroll->gross_pay;
        $ot      = (float) ($payroll->overtime_hours ?? 0);

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
                if ($pct > self::NET_CHANGE_THRESHOLD) {
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
        if ($ot > self::OT_THRESHOLD_HOURS) {
            $created += $this->flag($payroll, $period, PayrollAnomalyType::ExcessiveOt, [
                'overtime_hours' => $ot,
            ]);
        }

        // 4. High deduction ratio
        if ($gross > 0) {
            $deductions = $gross - $current;
            $ratio = $deductions / $gross;
            if ($ratio > self::DEDUCTION_RATIO) {
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
}
