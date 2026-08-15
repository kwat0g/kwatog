<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
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
use App\Modules\Payroll\Models\PayrollCycleClaim;
use App\Modules\Payroll\Models\PayrollDeductionDetail;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\Government\BirTaxComputationService;
use App\Modules\Payroll\Services\Government\PagibigComputationService;
use App\Modules\Payroll\Services\Government\PhilhealthComputationService;
use App\Modules\Payroll\Services\Government\SssComputationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
    public function __construct(
        private readonly SssComputationService $sss,
        private readonly PhilhealthComputationService $philhealth,
        private readonly PagibigComputationService $pagibig,
        private readonly BirTaxComputationService $bir,
        private readonly ThirteenthMonthService $thirteenthMonth,
        private readonly SettingsService $settings,
        private readonly PayrollPeriodService $periods,
    ) {}

    /**
     * Compute (or recompute) the payroll row for one employee in one period.
     *
     * Wrapped in DB::transaction so failed math leaves no partial rows.
     *
     * @param bool $internal True only when called by ProcessPayrollJob, which
     *        owns the period's Processing claim. External callers (the
     *        per-employee Recompute button) are additionally refused Approved
     *        and Processing periods — see the guard below.
     */
    public function computeForEmployee(
        PayrollPeriod $period,
        Employee $employee,
        bool $internal = false,
        ?string $claimToken = null,
    ): Payroll
    {
        // Locked = Finalized, Disbursed or Voided. Only Finalized was checked
        // before, so a disbursed run (money already paid) or a voided one could
        // be silently recomputed row by row via /payrolls/{id}/recompute.
        if ($period->status?->isLocked()) {
            throw new BusinessRuleException(sprintf(
                'Cannot recompute: payroll period is %s.',
                strtolower($period->status->label()),
            ));
        }

        // isLocked() alone left two holes for externally-triggered recomputes:
        //
        //   Approved   — a checker has already signed off on these amounts.
        //                Rewriting a row here changed approved payroll with no
        //                re-approval, defeating maker-checker (REC-04).
        //   Processing — the batch job owns the period; a concurrent single-row
        //                recompute races it and can be overwritten mid-flight
        //                (or double-apply loan deductions and adjustments).
        //
        // The batch job passes internal: true because its own claim is exactly
        // what puts the period into Processing.
        if (! $internal && in_array($period->status, [
            PayrollPeriodStatus::Approved,
            PayrollPeriodStatus::Processing,
        ], true)) {
            throw new BusinessRuleException(
                $period->status === PayrollPeriodStatus::Approved
                    ? 'Cannot recompute: this period is already approved. Void it or force-unlock to make changes.'
                    : 'Cannot recompute: a compute run is currently in progress for this period.',
            );
        }

        return DB::transaction(function () use ($period, $employee, $internal, $claimToken) {
            // Lock the period claim for the whole employee transaction. If a
            // stale worker was taken over after its previous employee, it must
            // stop before deleting/replacing or writing any row for this one.
            if ($internal) {
                $period = $this->periods->assertComputeClaim($period, $claimToken);
            }

            // Wipe any prior rows for this employee+period (clean recompute).
            $existing = Payroll::where('payroll_period_id', $period->id)
                ->where('employee_id', $employee->id)
                ->first();
            $replacedId = $existing?->id; // remembered so we can re-parent adjustment FKs after new row is inserted
            if ($existing) {
                PayrollDeductionDetail::where('payroll_id', $existing->id)->delete();

                // Restore loan balances BEFORE the payment rows are removed.
                // reverseLoanDeductions() reads loan_payments to know how much
                // to give back and deletes each row as it goes; the previous
                // code bulk-deleted them first, so the reversal always found an
                // empty set. Loan balances were therefore never restored while
                // the fresh run deducted the amortization again — every
                // recompute silently over-credited the loan by one instalment.
                $this->reverseLoanDeductions($existing);

                // Release adjustments consumed by the run we are replacing so
                // they are re-applied below. Without resetting applied_at the
                // re-run's `whereNull('applied_at')` filter skipped them and the
                // employee silently lost every adjustment on recompute.
                $this->releaseAppliedAdjustments($existing);

                // Roll back this row's 13th-month accrual contribution — the
                // accrual is a running additive total, so leaving it in place
                // double-counted basic pay on every recompute.
                $this->thirteenthMonth->reverseAccrual($existing);

                // applied_to_payroll_id is nullable — safe to null out before deletion.
                PayrollAdjustment::where('applied_to_payroll_id', $existing->id)
                    ->update(['applied_to_payroll_id' => null]);

                // original_payroll_id is NOT NULL (no cascade in schema), so we cannot null it.
                // We re-parent those references to the replacement payroll AFTER it is created (see below).

                $existing->delete();
            }

            $payType = $employee->pay_type instanceof \BackedEnum ? $employee->pay_type->value : (string) $employee->pay_type;

            // ─── Pay basis ───────────────────────────────────────
            // Both pay types are flat per cutoff (migration 0437). We resolve a
            // single monthly-equivalent figure and derive everything from it, so
            // basic pay and the government-contribution basis can never disagree
            // about what a month is — the defect that made daily pay unusable.
            $monthlySalary = $this->monthlyBasis($employee, $payType);

            // Daily + hourly are DERIVED divisors used for OT, night
            // differential, holiday premium and tardiness/undertime only. They
            // are never a basic-pay basis.
            $dailyRate  = Money::div($monthlySalary, $this->positivePolicy('payroll.work_days_per_month'), 4);
            if (Money::lte($dailyRate, '0')) {
                throw new BusinessRuleException("Employee {$employee->employee_no} has no authoritative pay rate for payroll calculation.");
            }
            $hourlyRate = Money::div($dailyRate, $this->positivePolicy('payroll.hours_per_day'), 4);

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
                $employee, $period, $monthlySalary, $payType,
            );

            // ─── Paid-leave pay ──────────────────────────────────
            // Both pay types earn a flat cutoff basic whether or not the
            // employee was on leave, so paid leave is already inside basic_pay.
            // The column is kept (payslips + GL reference it) but is always zero
            // now that the days-worked daily pay type is gone.
            $leavePay = Money::zero();

            // ─── Earnings stack ──────────────────────────────────
            $overtimePay  = $aggregates['ot_pay'];
            $nightDiffPay = $aggregates['nd_pay'];
            $holidayPay   = $aggregates['holiday_pay'];
            $tardiness    = $aggregates['tardiness_deduction'];
            $undertime    = $aggregates['undertime_deduction'];

            $earnings = Money::add($basicPay, $leavePay, $overtimePay, $nightDiffPay, $holidayPay);
            $grossPay = Money::sub(Money::sub($earnings, $tardiness), $undertime);
            if (Money::lt($grossPay, '0')) $grossPay = Money::zero();

            // ─── Government deductions (first half only) ─────────
            // Government contributions are assessed on the employee's ACTUAL
            // monthly compensation, not their nominal rate. For a full cutoff
            // those are the same figure. For a partial one — hired or separated
            // mid-month — the nominal rate overstates what they earned, pushing
            // them into a higher SSS/PhilHealth/Pag-IBIG bracket than their pay
            // supports: a 3-of-15-day cutoff earned ₱1,892 but was assessed a
            // full month's ₱1,623, an 86% deduction ratio that clamped net to
            // near zero and raised high_deduction + large_change flags (which
            // block finalize()).
            //
            // This is the same class of defect that made the daily pay type
            // unusable — basic pay and the contribution basis disagreeing about
            // the period they describe. It predates the semi-monthly conversion:
            // mid-period HIRES have always been assessed this way. Scaling the
            // basis by the employed fraction makes the two agree from both ends.
            $govBasis = Money::round2(Money::mul(
                $monthlySalary,
                $this->employedDayFraction($employee, $period),
            ));
            $sssEe = $sssEr = $phEe = $phEr = $pgEe = $pgEr = $wht = '0.00';

            if ($period->is_first_half && ! $period->is_thirteenth_month) {
                $effectiveOn = $period->payroll_date;
                $sssR = $this->sss->compute($govBasis, $effectiveOn);
                $phR  = $this->philhealth->compute($govBasis, $effectiveOn);
                $pgR  = $this->pagibig->compute($govBasis, $effectiveOn);
                $sssEe = $sssR['ee']; $sssEr = $sssR['er'];
                $phEe  = $phR['ee'];  $phEr  = $phR['er'];
                $pgEe  = $pgR['ee'];  $pgEr  = $pgR['er'];

                // BIR taxable = gross - employee gov contributions
                $taxable = Money::sub(Money::sub(Money::sub($grossPay, $sssEe), $phEe), $pgEe);
                if (Money::lt($taxable, '0')) $taxable = Money::zero();

                // De minimis benefits: the portion ABOVE the statutory limit is
                // taxable compensation and must be added to the WHT base. The
                // non-taxable portion is exempt and never touches tax. Guarded so
                // the calculator works unchanged if the module isn't installed.
                $taxable = Money::add($taxable, $this->deMinimisTaxableExcess($employee, $period));
                if (Money::lt($taxable, '0')) $taxable = Money::zero();

                $wht = $this->bir->compute($taxable, 'semi_monthly', $period->payroll_date);
            }

            // ─── Persist payroll row (without deductions yet) ────
            $payroll = Payroll::create([
                'payroll_period_id' => $period->id,
                'employee_id'       => $employee->id,
                'pay_type'          => $payType,
                'days_worked'       => $aggregates['days_worked'],
                'basic_pay'         => $basicPay,
                'leave_pay'         => $leavePay,
                'overtime_pay'      => $overtimePay,
                'night_diff_pay'    => $nightDiffPay,
                'holiday_pay'       => $holidayPay,
                // Persisted so the GL posting can balance (earnings are debited
                // gross, so the lateness withheld must be credited back) and so
                // the payslip can itemise what was actually deducted.
                'tardiness_deduction' => $tardiness,
                'undertime_deduction' => $undertime,
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

            // ─── Cycle claim: the double-pay guard ───────────────
            // Scoped periods (migration 0438) let several periods cover one
            // cutoff, so "one payroll row per period+employee" is no longer
            // enough — the same person could be paid by two different scoped
            // periods for the same dates, taking two sets of gov contributions
            // and two loan amortizations.
            //
            // payroll_cycle_claims has a UNIQUE (employee_id, cycle_key), so
            // this insert is the authoritative gate. It sits INSIDE the same
            // transaction as the payroll row, so a rejected claim rolls the
            // whole computation back and nothing is half-written. Two
            // concurrent workers racing on different periods cannot both win.
            $this->claimCycle($payroll, $period, $employee);

            // ─── Re-parent adjustment FKs from replaced payroll ──
            // original_payroll_id is NOT NULL with no ON DELETE rule; point references
            // at the new replacement row so the audit trail is preserved.
            if ($replacedId !== null) {
                PayrollAdjustment::where('original_payroll_id', $replacedId)
                    ->update(['original_payroll_id' => $payroll->id]);
            }

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
     * Stake this employee's claim on the period's pay cycle.
     *
     * Fails loudly when another (non-voided) period has already paid them for
     * the same cutoff. The message names the offending period so HR can fix the
     * scope rather than guess.
     *
     * A recompute of the SAME period is fine: the old payroll row was deleted
     * above and payroll_cycle_claims cascades on payroll_id, so the previous
     * claim went with it inside this transaction.
     */
    private function claimCycle(Payroll $payroll, PayrollPeriod $period, Employee $employee): void
    {
        $cycleKey = $period->cycleKey();

        // Read first so the common case gets a message naming the period that
        // already paid this employee. This is NOT the guard — a concurrent
        // worker can still slip between this read and the insert below — but it
        // has to happen BEFORE the insert, because PostgreSQL aborts the entire
        // transaction on a constraint violation (SQLSTATE 25P02) and no further
        // query can run on it. Diagnosing after the fact is impossible.
        $holder = PayrollCycleClaim::query()
            ->where('employee_id', $employee->id)
            ->where('cycle_key', $cycleKey)
            ->with('period')
            ->first();

        if ($holder) {
            throw new BusinessRuleException(sprintf(
                'Employee %s was already paid for this pay cycle by period %s. Two payroll periods cannot pay the same employee for %s–%s — narrow this period\'s scope, or void the other period first.',
                $employee->employee_no,
                $holder->period?->label() ?? 'another period',
                $period->period_start->format('Y-m-d'),
                $period->period_end->format('Y-m-d'),
            ));
        }

        try {
            PayrollCycleClaim::create([
                'employee_id'       => $employee->id,
                'payroll_id'        => $payroll->id,
                'payroll_period_id' => $period->id,
                'cycle_key'         => $cycleKey,
            ]);
        } catch (QueryException $e) {
            // The race the read above cannot cover: another worker claimed this
            // employee for the same cycle in the microseconds since. The unique
            // index is what actually makes double payment impossible; this is
            // the only place that outcome is observable. Anything that is not a
            // unique violation is a real DB fault and must not be flattened
            // into a business-rule message.
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            throw new BusinessRuleException(sprintf(
                'Employee %s was claimed by a concurrent payroll run for the same pay cycle (%s). Re-run compute once the other run has finished.',
                $employee->employee_no,
                $cycleKey,
            ));
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // PG: SQLSTATE 23505. SQLite (test fallback): 23000 with a message.
        return in_array($e->getCode(), ['23505', '23000'], true)
            || str_contains(strtolower($e->getMessage()), 'unique');
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

            // Overtime (REC-09): hours × hourly × dayRate × OT premium, where
            // the OT premium is 1.25 on an ordinary day (rate == 1.0) and 1.30
            // on any premium day (rest day / holiday, rate > 1.0). `rate`
            // already carries the day-type multiplier resolved by the DTR.
            if (bccomp($otHrs, '0', 2) > 0) {
                $otPremium = bccomp($rate, '1.00', 4) > 0
                    ? $this->positivePolicy('payroll.overtime.premium_day_multiplier')
                    : $this->positivePolicy('payroll.overtime.ordinary_multiplier');
                $otPay = Money::add($otPay, Money::mul(Money::mul(Money::mul($otHrs, $hourlyRate), $otPremium), $rate));
            }

            // Night differential: hours × hourly × 0.10 (additive premium)
            if (bccomp($ndHrs, '0', 2) > 0) {
                $ndPay = Money::add($ndPay, Money::mul(Money::mul($ndHrs, $hourlyRate), $this->nonNegativePolicy('payroll.night_differential_rate')));
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
     * Basic pay calculation — mid-period hire pro-ration and mid-cycle salary
     * changes (OGAMI-011). Monthly-salaried only; see migration 0437.
     *
     * Compatibility contract: when an employee has NO employee_salary_history
     * rows, this behaves EXACTLY as the legacy implementation (uses
     * $monthlySalary verbatim). Proration only kicks in when a salary change's
     * effective_date falls strictly inside the period.
     */
    private function computeBasicPay(
        Employee $employee,
        PayrollPeriod $period,
        string $monthlySalary,
        string $payType,
    ): string {
        // ─── Mid-cycle salary change proration (OGAMI-011) ───────
        // Only engages when there is at least one salary-history row whose
        // effective_date lands strictly inside (period_start, period_end].
        $segments = $this->salarySegments($employee, $period, $monthlySalary, $payType);
        if ($segments !== null) {
            // Mid-cycle raise: blend the segments, then apply the same
            // employment-window proration the flat path uses. Without this a
            // raise inside the cutoff would silently bypass hire/separation
            // proration and pay a leaver the full blended half-month.
            return Money::round2(Money::mul(
                $this->basicPayFromSegments($period, $segments),
                $this->employedDayFraction($employee, $period),
            ));
        }

        // One cutoff's basic = monthly equivalent / 2. Identical for both pay
        // types: a semi_monthly employee's monthly basis is already rate × 2.
        $halfBasic = Money::div($monthlySalary, '2', 4);

        // Pro-rate the days actually covered by employment. A flat cutoff rate
        // is only correct for someone employed the WHOLE cutoff; joining or
        // leaving partway means they are owed a fraction of it.
        return Money::round2(
            Money::mul($halfBasic, $this->employedDayFraction($employee, $period)),
        );
    }

    /**
     * What fraction of this cutoff's calendar days was the employee employed?
     *
     * '1.0000' for a full cutoff — the overwhelmingly common case, and the value
     * that keeps an unchanged run byte-identical to the pre-proration behaviour.
     *
     * Both ends are handled:
     *
     *   hire date inside the cutoff        → paid from the hire date onward
     *   separation date inside the cutoff  → paid up to the last working day
     *
     * The separation half matters because basic pay is now FLAT (migration 0437
     * retired the days-worked daily type). Someone who resigns on day 3 of a
     * 1–15 cutoff used to earn 3 × daily_rate; without this they would bank the
     * entire half-month. FinalPayService::lastSalaryProRated() reads
     * payroll.basic_pay verbatim when a computed row exists, so the inflated
     * figure would flow straight into final pay — roughly ₱6,880 on a ₱9,460
     * cutoff, per separation.
     */
    private function employedDayFraction(Employee $employee, PayrollPeriod $period): string
    {
        $periodStart = $period->period_start;
        $periodEnd   = $period->period_end;

        $from = $employee->date_hired && $employee->date_hired->gt($periodStart)
            ? $employee->date_hired
            : $periodStart;

        $separationDate = $this->separationDate($employee);
        $to = $separationDate && $separationDate->lt($periodEnd)
            ? $separationDate
            : $periodEnd;

        // Employment window does not overlap the cutoff at all (hired after it
        // ended, or separated before it began). Nothing is owed.
        if ($to->lt($from)) {
            return '0.0000';
        }

        $totalDays   = max(1, $periodStart->diffInDays($periodEnd) + 1);
        $coveredDays = $from->diffInDays($to) + 1;

        if ($coveredDays >= $totalDays) {
            return '1.0000';
        }

        return bcdiv((string) $coveredDays, (string) $totalDays, 4);
    }

    /**
     * The employee's last working day, if a separation is on record.
     *
     * Read from clearances.separation_date — the authoritative last day, set when
     * the separation is initiated. Guarded so the calculator keeps working if the
     * clearance table is absent, and takes the EARLIEST separation date on record
     * so a re-initiated separation cannot extend paid days.
     */
    private function separationDate(Employee $employee): ?\Illuminate\Support\Carbon
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('clearances')) {
            return null;
        }

        $date = DB::table('clearances')
            ->where('employee_id', $employee->id)
            ->whereNull('deleted_at')
            ->whereNotNull('separation_date')
            ->min('separation_date');

        return $date === null ? null : \Illuminate\Support\Carbon::parse($date)->startOfDay();
    }

    /**
     * Resolve effective salary segments across the period.
     *
     * Returns null when there is no mid-period salary change to honor — the
     * caller then runs the legacy code path verbatim (the compatibility
     * guarantee). When a change DOES land inside the period, returns an ordered
     * list of day-spans each tagged with the monthly rate in force for that span.
     *
     * @return array<int, array{days:int, monthly:string}>|null
     */
    private function salarySegments(
        Employee $employee,
        PayrollPeriod $period,
        string $monthlySalary,
        string $payType,
    ): ?array {
        // Cheap existence guard first — keeps the no-history path allocation-free.
        $history = \App\Modules\HR\Models\EmployeeSalaryHistory::query()
            ->where('employee_id', $employee->id)
            ->whereDate('effective_date', '<=', $period->period_end)
            ->orderBy('effective_date')
            ->orderBy('id')
            ->get();

        if ($history->isEmpty()) {
            return null;
        }

        // Does any change take effect strictly AFTER period_start and on/before
        // period_end? If not, the current salary already reflects everything and
        // we defer to the legacy path (no proration needed).
        $changesInside = $history->first(function ($h) use ($period) {
            $eff = \Illuminate\Support\Carbon::parse($h->effective_date);
            return $eff->gt($period->period_start) && $eff->lte($period->period_end);
        });
        if ($changesInside === null) {
            return null;
        }

        // Salary in force at period_start = latest history row effective on or
        // before period_start, else the employee's current values (the row set
        // may only describe the raise, not the starting salary).
        $startRow = $history->last(function ($h) use ($period) {
            return \Illuminate\Support\Carbon::parse($h->effective_date)->lte($period->period_start);
        });
        $curMonthly = $this->historyMonthly($startRow, $payType) ?? $monthlySalary;

        // Build day-by-day cursor, switching rates as effective dates pass.
        $insideChanges = $history
            ->filter(function ($h) use ($period) {
                $eff = \Illuminate\Support\Carbon::parse($h->effective_date);
                return $eff->gt($period->period_start) && $eff->lte($period->period_end);
            })
            ->values();

        $segments = [];
        $cursor   = \Illuminate\Support\Carbon::parse($period->period_start)->startOfDay();
        $end      = \Illuminate\Support\Carbon::parse($period->period_end)->startOfDay();
        $changeIdx = 0;
        $spanDays  = 0;

        for ($day = $cursor->copy(); $day->lte($end); $day->addDay()) {
            // Apply any change effective on this day before counting it.
            while ($changeIdx < $insideChanges->count()
                && \Illuminate\Support\Carbon::parse($insideChanges[$changeIdx]->effective_date)->startOfDay()->eq($day)) {
                if ($spanDays > 0) {
                    $segments[] = ['days' => $spanDays, 'monthly' => $curMonthly];
                    $spanDays = 0;
                }
                $curMonthly = $this->historyMonthly($insideChanges[$changeIdx], $payType) ?? $curMonthly;
                $changeIdx++;
            }
            $spanDays++;
        }
        if ($spanDays > 0) {
            $segments[] = ['days' => $spanDays, 'monthly' => $curMonthly];
        }

        return $segments;
    }

    /**
     * Monthly-equivalent salary carried by a salary-history row, or null when
     * the row holds no usable figure for this pay type.
     */
    private function historyMonthly(?object $row, string $payType): ?string
    {
        if ($row === null) {
            return null;
        }

        // Per-cutoff rate wins for a semi-monthly employee, but fall through to
        // the monthly column when the row predates their switch to semi-monthly
        // (rows of both shapes coexist for someone whose pay type changed).
        if ($payType === PayType::SemiMonthly->value
            && $row->semi_monthly_rate !== null
            && Money::gt((string) $row->semi_monthly_rate, '0')) {
            return Money::mul((string) $row->semi_monthly_rate, '2');
        }

        return $row->basic_monthly_salary !== null && Money::gt((string) $row->basic_monthly_salary, '0')
            ? (string) $row->basic_monthly_salary
            : null;
    }

    /**
     * Monthly-equivalent salary for an employee.
     *
     * The single basis both basic pay and government contributions read, so the
     * two can never diverge:
     *
     *   monthly       → basic_monthly_salary
     *   semi_monthly  → semi_monthly_rate × 2
     */
    private function monthlyBasis(Employee $employee, string $payType): string
    {
        if ($payType === PayType::SemiMonthly->value) {
            if ($employee->semi_monthly_rate === null) {
                throw new BusinessRuleException("Employee {$employee->employee_no} has no semi-monthly rate for payroll calculation.");
            }
            return Money::mul((string) $employee->semi_monthly_rate, '2');
        }

        if ($employee->basic_monthly_salary === null) {
            throw new BusinessRuleException("Employee {$employee->employee_no} has no monthly salary for payroll calculation.");
        }

        return (string) $employee->basic_monthly_salary;
    }

    /**
     * Compute basic pay from ordered salary segments.
     *
     * Each segment earns (half-month-basic at that salary) × (segment days ÷
     * total period days).
     *
     * @param  array<int, array{days:int, monthly:string}>  $segments
     */
    private function basicPayFromSegments(PayrollPeriod $period, array $segments): string
    {
        $totalDays = 0;
        foreach ($segments as $s) {
            $totalDays += $s['days'];
        }
        $totalDays = max(1, $totalDays);

        // Blended half-month basic weighted by calendar-day share.
        $total = Money::zero();
        foreach ($segments as $s) {
            $halfBasic = Money::div($s['monthly'], '2', 4);
            $factor = bcdiv((string) $s['days'], (string) $totalDays, 6);
            $total = Money::add($total, Money::mul($halfBasic, $factor));
        }
        return Money::round2($total);
    }

    /**
     * Salary basis used for monthly gov contribution calculations.
     */
    /**
     * Taxable-excess de minimis for the employee in this period's month.
     *
     * The portion of de minimis benefits above the statutory ceiling is taxable
     * compensation. Returns '0.00' (and never throws) when the de minimis module
     * is absent — keeping the calculator backward compatible.
     */
    private function deMinimisTaxableExcess(Employee $employee, PayrollPeriod $period): string
    {
        if (! class_exists(\App\Modules\Payroll\Services\DeMinimisService::class)) {
            return '0.00';
        }
        try {
            $year  = (int) $period->payroll_date->format('Y');
            $month = (int) $period->payroll_date->format('n');
            return app(\App\Modules\Payroll\Services\DeMinimisService::class)
                ->getTaxableExcessForEmployee($employee, $year, $month);
        } catch (\Throwable $e) {
            Log::warning('De minimis taxable-excess lookup failed; treating as 0', [
                'employee_id' => $employee->id,
                'period_id'   => $period->id,
                'error'       => $e->getMessage(),
            ]);
            return '0.00';
        }
    }

    private function positivePolicy(string $key): string
    {
        $value = $this->settings->get($key);
        if (! is_numeric($value) || (float) $value <= 0) {
            throw new BusinessRuleException("Required payroll setting {$key} is missing or invalid.");
        }
        return (string) $value;
    }

    private function nonNegativePolicy(string $key): string
    {
        $value = $this->settings->get($key);
        if (! is_numeric($value) || (float) $value < 0) {
            throw new BusinessRuleException("Required payroll setting {$key} is missing or invalid.");
        }
        return (string) $value;
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
            // Keep the loan-row lock order stable across payroll runs and
            // manual payments/reversals. Decisions below are made only from
            // the locked, current row and all detail + aggregate writes are
            // covered by computeForEmployee's transaction.
            ->orderBy('id')
            ->lockForUpdate()
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
            $deduction = Money::lt((string) $loan->balance, $perPeriod)
                ? Money::round2((string) $loan->balance)
                : Money::round2($perPeriod);
            if (Money::isZero($deduction)) {
                continue;
            }

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

            // Reconcile from the immutable payment ledger. The loan row is
            // already locked above, so manual payments and this deduction
            // cannot race or leave the denormalized summary drifting.
            app(\App\Modules\Loans\Services\LoanService::class)
                ->reconcileAggregates($loan, $period->payroll_date->toDateString());
        }

        return $total;
    }

    /**
     * Return adjustments consumed by a payroll we are about to replace to the
     * Approved/unapplied pool so the fresh run picks them up again.
     *
     * applyApprovedAdjustments() selects on `whereNull('applied_at')`, so an
     * adjustment marked Applied by the previous run was invisible to the
     * recompute — the employee's back-pay or deduction silently vanished from
     * their payslip while the adjustment row still read "Applied".
     */
    private function releaseAppliedAdjustments(Payroll $previous): void
    {
        PayrollAdjustment::query()
            ->where('applied_to_payroll_id', $previous->id)
            ->where('status', PayrollAdjustmentStatus::Applied->value)
            ->get()
            ->each(function (PayrollAdjustment $adj) {
                $adj->forceFill([
                    'status'                => PayrollAdjustmentStatus::Approved->value,
                    'applied_at'            => null,
                    'applied_to_payroll_id' => null,
                ])->save();
            });
    }

    /**
     * Reverses loan_payments + adjusts loan balances for a recompute.
     */
    private function reverseLoanDeductions(Payroll $previous): void
    {
        $payments = LoanPayment::query()
            ->where('payroll_id', $previous->id)
            ->orderBy('loan_id')
            ->orderBy('id')
            ->get();
        if ($payments->isEmpty()) {
            return;
        }

        // Recompute must serialize against both payroll deduction and manual
        // payment paths. Lock every affected loan in id order before changing
        // any aggregate, while keeping the existing reverse/delete semantics.
        $loans = EmployeeLoan::query()
            ->whereIn('id', $payments->pluck('loan_id')->unique()->sort()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($payments as $p) {
            $loan = $loans->get($p->loan_id);
            if (! $loan) {
                continue;
            }
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
