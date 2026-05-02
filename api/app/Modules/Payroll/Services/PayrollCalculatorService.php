<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Common\Support\Money;
use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\HR\Enums\PayType;
use App\Modules\HR\Models\Employee;
use App\Modules\Loans\Enums\LoanStatus;
use App\Modules\Loans\Enums\LoanType;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Loans\Models\LoanPayment;
use App\Modules\Payroll\Enums\DeductionType;
use App\Modules\Payroll\Enums\PayrollAdjustmentStatus;
use App\Modules\Payroll\Enums\PayrollAdjustmentType;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollDeductionDetail;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\Government\BirTaxComputationService;
use App\Modules\Payroll\Services\Government\PagibigComputationService;
use App\Modules\Payroll\Services\Government\PhilhealthComputationService;
use App\Modules\Payroll\Services\Government\SssComputationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The heart of Sprint 3 — orchestrates per-employee payroll computation.
 *
 * Inputs:
 *   - PayrollPeriod (semi-monthly window OR a 13th-month special period)
 *   - Employee (active, hired ≤ period_end)
 *   - Attendance rows from Sprint 2 (pre-computed by DTRComputationService)
 *
 * Outputs:
 *   - One Payroll row written via Eloquent (UNIQUE on period + employee)
 *   - PayrollDeductionDetail rows for each line item (gov, loans, adjustments)
 *   - Loan balance decrement + LoanPayment trace row
 *   - 13th-month accrual updated (running total)
 *
 * Math conventions:
 *   - All amounts handled as strings via App\Common\Support\Money
 *   - 22 working days/month divisor for monthly→daily conversion
 *   - 8 hours/day divisor for daily→hourly conversion
 *   - Holiday "premium" (the bit ABOVE 100%) goes into holiday_pay
 *     so basic_pay stays clean and easy to reason about
 *   - Government deductions only on the FIRST half of the month (PH convention,
 *     per CLAUDE.md). Second half: zero gov deductions.
 *   - Net pay clamped at zero — never negative paycheck. Excess deductions roll
 *     to next period via adjustment.
 */
class PayrollCalculatorService
{
    private const DAYS_PER_MONTH = '22';
    private const HOURS_PER_DAY  = '8';
    private const OT_PREMIUM     = '1.25';
    private const ND_PREMIUM     = '0.10';

    public function __construct(
        private readonly SssComputationService $sss,
        private readonly PhilhealthComputationService $philhealth,
        private readonly PagibigComputationService $pagibig,
        private readonly BirTaxComputationService $bir,
        private readonly ThirteenthMonthService $thirteenthMonth,
    ) {}

    /**
     * Compute (or recompute) the payroll row for one employee in one period.
     *
     * Wrapped in DB::transaction so failed math leaves no partial rows.
     */
    public function computeForEmployee(PayrollPeriod $period, Employee $employee): Payroll
    {
        if ($period->status === PayrollPeriodStatus::Finalized) {
            throw new RuntimeException('Cannot recompute: payroll period is finalized.');
        }

        return DB::transaction(function () use ($period, $employee) {
            // Wipe any prior rows for this employee+period (clean recompute).
            $existing = Payroll::where('payroll_period_id', $period->id)
                ->where('employee_id', $employee->id)
                ->first();
            if ($existing) {
                PayrollDeductionDetail::where('payroll_id', $existing->id)->delete();
                LoanPayment::where('payroll_id', $existing->id)->delete(); // reverse loan deductions
                $this->reverseLoanDeductions($existing);
                $existing->delete();
            }

            $payType = $employee->pay_type instanceof \BackedEnum ? $employee->pay_type->value : (string) $employee->pay_type;

            // ─── Hourly + daily rates ────────────────────────────
            $monthlySalary = (string) ($employee->basic_monthly_salary ?? '0');
            $dailyRate     = $payType === PayType::Daily->value
                ? (string) ($employee->daily_rate ?? '0')
                : Money::div($monthlySalary, self::DAYS_PER_MONTH, 4);
            $hourlyRate    = Money::div($dailyRate, self::HOURS_PER_DAY, 4);

            // ─── Load attendance rows for this period ────────────
            /** @var \Illuminate\Support\Collection<int, Attendance> $attendances */
            $attendances = Attendance::query()
                ->where('employee_id', $employee->id)
                ->whereBetween('date', [$period->period_start, $period->period_end])
                ->get();

            // ─── Aggregate attendance amounts ────────────────────
            $aggregates = $this->aggregateAttendance($attendances, $hourlyRate);

            // ─── Basic pay ───────────────────────────────────────
            $basicPay = $this->computeBasicPay(
                $employee, $period, $aggregates['days_worked'], $monthlySalary, $dailyRate,
            );

            // ─── Earnings stack ──────────────────────────────────
            $overtimePay  = $aggregates['ot_pay'];
            $nightDiffPay = $aggregates['nd_pay'];
            $holidayPay   = $aggregates['holiday_pay'];
            $tardiness    = $aggregates['tardiness_deduction'];
            $undertime    = $aggregates['undertime_deduction'];

            $earnings = Money::add($basicPay, $overtimePay, $nightDiffPay, $holidayPay);
            $grossPay = Money::sub(Money::sub($earnings, $tardiness), $undertime);
            if (Money::lt($grossPay, '0')) $grossPay = Money::zero();

            // ─── Government deductions (first half only) ─────────
            $govBasis = $this->governmentDeductionBasis($employee, $monthlySalary, $dailyRate);
            $sssEe = $sssEr = $phEe = $phEr = $pgEe = $pgEr = $wht = '0.00';

            if ($period->is_first_half && ! $period->is_thirteenth_month) {
                $sssR = $this->sss->compute($govBasis);
                $phR  = $this->philhealth->compute($govBasis);
                $pgR  = $this->pagibig->compute($govBasis);
                $sssEe = $sssR['ee']; $sssEr = $sssR['er'];
                $phEe  = $phR['ee'];  $phEr  = $phR['er'];
                $pgEe  = $pgR['ee'];  $pgEr  = $pgR['er'];

                // BIR taxable = gross - employee gov contributions
                $taxable = Money::sub(Money::sub(Money::sub($grossPay, $sssEe), $phEe), $pgEe);
                if (Money::lt($taxable, '0')) $taxable = Money::zero();
                $wht = $this->bir->compute($taxable, 'semi_monthly');
            }

            // ─── Persist payroll row (without deductions yet) ────
            $payroll = Payroll::create([
                'payroll_period_id' => $period->id,
                'employee_id'       => $employee->id,
                'pay_type'          => $payType,
                'days_worked'       => $aggregates['days_worked'],
                'basic_pay'         => $basicPay,
                'overtime_pay'      => $overtimePay,
                'night_diff_pay'    => $nightDiffPay,
                'holiday_pay'       => $holidayPay,
                'gross_pay'         => $grossPay,
                'sss_ee' => $sssEe, 'sss_er' => $sssEr,
                'philhealth_ee' => $phEe, 'philhealth_er' => $phEr,
                'pagibig_ee' => $pgEe, 'pagibig_er' => $pgEr,
                'withholding_tax' => $wht,
                'loan_deductions' => '0.00', 'other_deductions' => '0.00',
                'adjustment_amount' => '0.00',
                'total_deductions' => '0.00', 'net_pay' => '0.00',
                'computed_at' => now(),
            ]);

            // ─── Deduction detail rows for gov ───────────────────
            $this->addDeductionDetail($payroll, DeductionType::Sss, 'SSS Employee Share', $sssEe);
            $this->addDeductionDetail($payroll, DeductionType::Philhealth, 'PhilHealth Employee Share', $phEe);
            $this->addDeductionDetail($payroll, DeductionType::Pagibig, 'Pag-IBIG Employee Share', $pgEe);
            $this->addDeductionDetail($payroll, DeductionType::WithholdingTax, 'BIR Withholding Tax', $wht);

            // ─── Loan auto-deductions ────────────────────────────
            $loanTotal = $this->applyLoanDeductions($payroll, $employee, $period);

            // ─── Adjustment carry-over ───────────────────────────
            $adjAmount = $this->applyApprovedAdjustments($payroll, $employee, $period);

            // ─── Totals ──────────────────────────────────────────
            $totalDeductions = Money::add($sssEe, $phEe, $pgEe, $wht, $loanTotal);
            $netPay = Money::sub($grossPay, $totalDeductions);
            $netPay = Money::add($netPay, $adjAmount); // signed

            if (Money::lt($netPay, '0')) {
                Log::warning('Payroll net clamped to zero', [
                    'payroll_id' => $payroll->id,
                    'employee_id' => $employee->id,
                    'period_id' => $period->id,
                    'computed_net' => $netPay,
                ]);
                $netPay = Money::zero();
            }

            $payroll->update([
                'loan_deductions'   => $loanTotal,
                'adjustment_amount' => $adjAmount,
                'total_deductions'  => $totalDeductions,
                'net_pay'           => $netPay,
            ]);

            // ─── 13th month accrual hook ─────────────────────────
            $this->thirteenthMonth->accrue($payroll->fresh(['period']));

            return $payroll->fresh(['deductionDetails', 'employee.department', 'employee.position', 'period']);
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Attendance>  $attendances
     * @return array{
     *   days_worked: string,
     *   ot_pay: string,
     *   nd_pay: string,
     *   holiday_pay: string,
     *   tardiness_deduction: string,
     *   undertime_deduction: string,
     * }
     */
    private function aggregateAttendance($attendances, string $hourlyRate): array
    {
        $daysWorked = '0';
        $otPay = $ndPay = $holiday = $tardiness = $undertime = Money::zero();

        foreach ($attendances as $att) {
            $isWorked = ! in_array($att->status, [AttendanceStatus::Absent, AttendanceStatus::OnLeave], true)
                && (float) $att->regular_hours > 0;
            if ($isWorked) {
                $daysWorked = bcadd($daysWorked, '1.0', 1);
            }

            $regHrs = (string) $att->regular_hours;
            $otHrs  = (string) $att->overtime_hours;
            $ndHrs  = (string) $att->night_diff_hours;
            $rate   = (string) $att->day_type_rate; // 1.0 default, 1.3 special, 2.0 regular holiday, etc.

            // Holiday premium = (rate - 1.0) × regular hours × hourly rate.
            // Captures the EXTRA earned for working a holiday/restday.
            $premium = bcsub($rate, '1.00', 4);
            if (bccomp($premium, '0', 4) > 0 && bccomp($regHrs, '0', 2) > 0) {
                $holiday = Money::add($holiday, Money::mul(Money::mul($regHrs, $hourlyRate), $premium));
            }
            // Holiday with no regular work but the employee was paid (regular holiday rule):
            // day_type_rate stays at 1.0, regular_hours = 8 (from DTR engine), already covered.

            // Overtime: hours × hourly × 1.25 × rate (rate already holds holiday multiplier)
            if (bccomp($otHrs, '0', 2) > 0) {
                $otPay = Money::add($otPay, Money::mul(Money::mul(Money::mul($otHrs, $hourlyRate), self::OT_PREMIUM), $rate));
            }

            // Night differential: hours × hourly × 0.10 (additive premium)
            if (bccomp($ndHrs, '0', 2) > 0) {
                $ndPay = Money::add($ndPay, Money::mul(Money::mul($ndHrs, $hourlyRate), self::ND_PREMIUM));
            }

            // Tardiness / undertime in minutes — convert to hours and deduct.
            if ($att->tardiness_minutes > 0) {
                $h = bcdiv((string) $att->tardiness_minutes, '60', 4);
                $tardiness = Money::add($tardiness, Money::mul($h, $hourlyRate));
            }
            if ($att->undertime_minutes > 0) {
                $h = bcdiv((string) $att->undertime_minutes, '60', 4);
                $undertime = Money::add($undertime, Money::mul($h, $hourlyRate));
            }
        }

        return [
            'days_worked'         => $daysWorked,
            'ot_pay'              => $otPay,
            'nd_pay'              => $ndPay,
            'holiday_pay'         => $holiday,
            'tardiness_deduction' => $tardiness,
            'undertime_deduction' => $undertime,
        ];
    }

    /**
     * Basic pay calculation — handles monthly vs daily, mid-period hire pro-ration.
     */
    private function computeBasicPay(
        Employee $employee,
        PayrollPeriod $period,
        string $daysWorked,
        string $monthlySalary,
        string $dailyRate,
    ): string {
        $payType = $employee->pay_type instanceof \BackedEnum ? $employee->pay_type->value : (string) $employee->pay_type;

        if ($payType === PayType::Daily->value) {
            return Money::mul($daysWorked, $dailyRate);
        }

        // Monthly: half-period basic = monthly_salary / 2
        $halfBasic = Money::div($monthlySalary, '2', 4);

        // Pro-rate if hired mid-period.
        $hireDate = $employee->date_hired;
        if ($hireDate && $hireDate->gt($period->period_start) && $hireDate->lte($period->period_end)) {
            $totalDays = max(1, $period->period_start->diffInDays($period->period_end) + 1);
            $workedDays = max(0, $hireDate->diffInDays($period->period_end) + 1);
            $factor = bcdiv((string) $workedDays, (string) $totalDays, 4);
            return Money::mul($halfBasic, $factor);
        }

        return Money::round2($halfBasic);
    }

    /**
     * Salary basis used for monthly gov contribution calculations.
     * For daily-rated employees we project: daily_rate × 22 (standard PH practice).
     */
    private function governmentDeductionBasis(Employee $employee, string $monthlySalary, string $dailyRate): string
    {
        $payType = $employee->pay_type instanceof \BackedEnum ? $employee->pay_type->value : (string) $employee->pay_type;
        if ($payType === PayType::Daily->value) {
            return Money::mul($dailyRate, self::DAYS_PER_MONTH);
        }
        return $monthlySalary;
    }

    private function addDeductionDetail(Payroll $payroll, DeductionType $type, string $description, string $amount): void
    {
        if (Money::isZero($amount)) return;
        PayrollDeductionDetail::create([
            'payroll_id'     => $payroll->id,
            'deduction_type' => $type->value,
            'description'    => $description,
            'amount'         => $amount,
        ]);
    }

    /**
     * Returns total loan deduction amount applied to this payroll.
     * Splits company_loan amortization across both halves; full amortization
     * for cash_advance to clear it faster (CA defaults to short tenure).
     */
    private function applyLoanDeductions(Payroll $payroll, Employee $employee, PayrollPeriod $period): string
    {
        if ($period->is_thirteenth_month) {
            return '0.00';
        }

        $loans = EmployeeLoan::query()
            ->where('employee_id', $employee->id)
            ->where('status', LoanStatus::Active->value)
            ->where('pay_periods_remaining', '>', 0)
            ->get();

        $total = '0.00';
        foreach ($loans as $loan) {
            $amort = (string) $loan->monthly_amortization;
            // Cash advance: full amount per period (drains in 1-2 periods).
            // Company loan: half the monthly amortization per semi-monthly period.
            $perPeriod = $loan->loan_type === LoanType::CashAdvance
                ? $amort
                : Money::div($amort, '2', 4);

            // Don't take more than the outstanding balance.
            $deduction = Money::lt((string) $loan->balance, $perPeriod) ? (string) $loan->balance : Money::round2($perPeriod);
            if (Money::isZero($deduction)) continue;

            $total = Money::add($total, $deduction);
            PayrollDeductionDetail::create([
                'payroll_id'     => $payroll->id,
                'deduction_type' => $loan->loan_type === LoanType::CashAdvance ? DeductionType::CashAdvance->value : DeductionType::Loan->value,
                'description'    => sprintf('%s · %s', $loan->loan_no, $loan->loan_type->value),
                'amount'         => $deduction,
                'reference_id'   => $loan->id,
            ]);

            // Loan accounting trace.
            LoanPayment::create([
                'loan_id'      => $loan->id,
                'payroll_id'   => $payroll->id,
                'amount'       => $deduction,
                'payment_date' => $period->payroll_date,
                'remarks'      => 'Auto-deduction from payroll',
            ]);

            // Update loan state.
            $loan->total_paid           = Money::add((string) $loan->total_paid, $deduction);
            $loan->balance              = Money::sub((string) $loan->balance, $deduction);
            $loan->pay_periods_remaining = max(0, $loan->pay_periods_remaining - 1);
            if (Money::lte($loan->balance, '0.00') || $loan->pay_periods_remaining === 0) {
                $loan->status = LoanStatus::Paid;
                $loan->balance = '0.00';
                $loan->end_date = $period->payroll_date;
            }
            $loan->save();
        }

        return $total;
    }

    /**
     * Reverses loan_payments + adjusts loan balances for a recompute.
     */
    private function reverseLoanDeductions(Payroll $previous): void
    {
        $payments = LoanPayment::where('payroll_id', $previous->id)->get();
        foreach ($payments as $p) {
            $loan = EmployeeLoan::find($p->loan_id);
            if (! $loan) continue;
            $loan->total_paid = Money::sub((string) $loan->total_paid, (string) $p->amount);
            $loan->balance    = Money::add((string) $loan->balance, (string) $p->amount);
            $loan->pay_periods_remaining = $loan->pay_periods_remaining + 1;
            if ($loan->status === LoanStatus::Paid) {
                $loan->status = LoanStatus::Active;
                $loan->end_date = null;
            }
            $loan->save();
            $p->delete();
        }
    }

    /**
     * Apply approved adjustments to this period's payroll. Returns signed total.
     * Each adjustment is marked Applied and linked to this payroll.
     */
    private function applyApprovedAdjustments(Payroll $payroll, Employee $employee, PayrollPeriod $period): string
    {
        if ($period->is_thirteenth_month) {
            return '0.00';
        }

        $adjustments = PayrollAdjustment::query()
            ->where('employee_id', $employee->id)
            ->where('status', PayrollAdjustmentStatus::Approved->value)
            ->whereNull('applied_at')
            ->get();

        $signedTotal = '0.00';
        foreach ($adjustments as $adj) {
            $sign = $adj->type instanceof PayrollAdjustmentType
                ? $adj->type->signMultiplier()
                : (PayrollAdjustmentType::from((string) $adj->type)->signMultiplier());
            $signed = Money::mul((string) $adj->amount, $sign);
            $signedTotal = Money::add($signedTotal, $signed);

            PayrollDeductionDetail::create([
                'payroll_id'     => $payroll->id,
                'deduction_type' => DeductionType::Adjustment->value,
                'description'    => $adj->type->label().' · '.\Illuminate\Support\Str::limit($adj->reason, 100),
                // store as positive in the detail — sign only matters for the payroll.adjustment_amount.
                'amount'         => Money::round2(ltrim($signed, '-')),
                'reference_id'   => $adj->id,
            ]);

            $adj->status = PayrollAdjustmentStatus::Applied;
            $adj->applied_at = now();
            $adj->applied_to_payroll_id = $payroll->id;
            $adj->save();
        }

        return $signedTotal;
    }
}
