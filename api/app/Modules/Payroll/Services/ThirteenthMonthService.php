<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Common\Support\Money;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\DeductionType;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollDeductionDetail;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Models\ThirteenthMonthAccrual;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 13th-month pay tracking.
 *
 * Sprint 3 builds the accrual hook into PayrollCalculatorService.
 * Sprint 3 / Task 28 adds the December run (`computeAndPay`) that creates the
 * dedicated payroll period and disburses the accrued amount.
 */
class ThirteenthMonthService
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Increment the running accrual for an employee + year by this payroll's
     * basic pay. Idempotent via UNIQUE (employee_id, year).
     *
     * Skipped when the payroll itself is part of a 13th-month period (we never
     * accrue the payout against itself).
     */
    public function accrue(Payroll $payroll): ?ThirteenthMonthAccrual
    {
        $period = $payroll->relationLoaded('period') ? $payroll->period : $payroll->period()->first();
        if (! $period || $period->is_thirteenth_month) {
            return null;
        }

        $year = (int) $period->period_start->format('Y');

        $accrual = ThirteenthMonthAccrual::firstOrCreate(
            ['employee_id' => $payroll->employee_id, 'year' => $year],
            ['total_basic_earned' => '0.00', 'accrued_amount' => '0.00', 'is_paid' => false],
        );

        // Don't double-accrue once paid for the year.
        if ($accrual->is_paid) {
            return $accrual;
        }

        $newTotal = Money::add((string) $accrual->total_basic_earned, (string) $payroll->basic_pay);
        $accrual->total_basic_earned = $newTotal;
        $accrual->accrued_amount     = Money::div($newTotal, '12', 2); // running estimate
        $accrual->save();

        return $accrual;
    }

    /**
     * Roll back the accrual contribution made by a payroll that is being
     * replaced by a recompute.
     *
     * accrue() adds basic_pay to a running total, so without this every
     * recompute of the same period inflated the employee's 13th-month base by
     * another half-month of basic pay. Called by PayrollCalculatorService just
     * before the old payroll row is deleted.
     */
    public function reverseAccrual(Payroll $payroll): ?ThirteenthMonthAccrual
    {
        $period = $payroll->relationLoaded('period') ? $payroll->period : $payroll->period()->first();
        if (! $period || $period->is_thirteenth_month) {
            return null;
        }

        $year = (int) $period->period_start->format('Y');

        $accrual = ThirteenthMonthAccrual::query()
            ->where('employee_id', $payroll->employee_id)
            ->where('year', $year)
            ->first();

        // Once the year has been paid out the accrual is closed — accrue()
        // refuses to add to it, so there is nothing to take back either.
        if (! $accrual || $accrual->is_paid) {
            return $accrual;
        }

        $newTotal = Money::sub((string) $accrual->total_basic_earned, (string) $payroll->basic_pay);
        if (Money::lt($newTotal, '0')) {
            $newTotal = Money::zero();
        }
        $accrual->total_basic_earned = $newTotal;
        $accrual->accrued_amount     = Money::div($newTotal, '12', 2);
        $accrual->save();

        return $accrual;
    }

    /**
     * @return ThirteenthMonthAccrual|null
     */
    public function getAccrual(Employee $employee, int $year): ?ThirteenthMonthAccrual
    {
        return ThirteenthMonthAccrual::query()
            ->where('employee_id', $employee->id)
            ->where('year', $year)
            ->first();
    }

    /**
     * Run the December 13th-month batch:
     *   1. Create a special PayrollPeriod (`is_thirteenth_month=true`)
     *   2. For each employee with an unpaid accrual:
     *      - finalize accrued_amount = total_basic_earned / 12
     *      - create a Payroll row with gross/net = accrued_amount and a
     *        ThirteenthMonth deduction-detail line so the payslip prints
     *        the line item.
     *      - mark accrual paid + linked
     *
     * Known simplification (Sprint 3 plan §5):
     *   The ₱90,000 BIR exemption above which 13th-month becomes taxable is NOT
     *   yet applied. Future enhancement: subtract excess-of-90K from withholding
     *   tax basis in the December BIR run.
     */
    public function computeAndPay(int $year, User $triggeredBy, ?string $payrollDate = null): PayrollPeriod
    {
        return DB::transaction(function () use ($year, $triggeredBy, $payrollDate) {
            $payDay = $this->settings->requiredInt('payroll.thirteenth_month.default_pay_day', 1, 31);
            $payDate = $payrollDate
                ? CarbonImmutable::parse($payrollDate)
                : CarbonImmutable::create($year, 12, $payDay);

            // One 13th-month period per year.
            $existing = PayrollPeriod::query()
                ->where('is_thirteenth_month', true)
                ->whereYear('period_start', $year)
                ->first();

            if ($existing && $existing->status === PayrollPeriodStatus::Finalized) {
                throw new BusinessRuleException("13th-month period for {$year} is already finalized.");
            }

            if ($existing) {
                $period = $existing;
            } else {
                $period = PayrollPeriod::create([
                    'period_start'        => "{$year}-12-01",
                    'period_end'          => "{$year}-12-31",
                    'payroll_date'        => $payDate->toDateString(),
                    'is_first_half'       => false,
                    'is_thirteenth_month' => true,
                    'created_by'          => $triggeredBy->id,
                ]);
                // status non-fillable; service-only.
                $period->forceFill(['status' => PayrollPeriodStatus::Draft->value])->save();
            }

            // Wipe any partial run so this is idempotent.
            $oldPayrollIds = Payroll::where('payroll_period_id', $period->id)->pluck('id');
            PayrollDeductionDetail::whereIn('payroll_id', $oldPayrollIds)->delete();
            // Clear the FK reference on accruals before deleting the payroll rows;
            // otherwise SQLite (and strict FK DBs) raise a constraint violation.
            ThirteenthMonthAccrual::whereIn('payroll_id', $oldPayrollIds)
                ->update(['payroll_id' => null]);
            Payroll::whereIn('id', $oldPayrollIds)->delete();

            $accruals = ThirteenthMonthAccrual::query()
                ->where('year', $year)
                ->where('is_paid', false)
                ->whereHas('employee', fn ($q) => $q->where('status', EmployeeStatus::Active->value))
                ->with('employee')
                ->get();

            foreach ($accruals as $accrual) {
                $emp = $accrual->employee;
                if (! $emp) continue;

                // Final canonical amount = total_basic_earned / 12.
                $amount = Money::div((string) $accrual->total_basic_earned, '12', 2);
                $amount = Money::round2($amount);
                if (Money::isZero($amount)) continue;

                $payroll = Payroll::create([
                    'payroll_period_id' => $period->id,
                    'employee_id'       => $emp->id,
                    'pay_type'          => $emp->pay_type instanceof \BackedEnum ? $emp->pay_type->value : (string) $emp->pay_type,
                    'days_worked'       => null,
                    'basic_pay'         => '0.00',
                    'overtime_pay'      => '0.00',
                    'night_diff_pay'    => '0.00',
                    'holiday_pay'       => '0.00',
                    'gross_pay'         => $amount,
                    'sss_ee' => '0.00', 'sss_er' => '0.00',
                    'philhealth_ee' => '0.00', 'philhealth_er' => '0.00',
                    'pagibig_ee' => '0.00', 'pagibig_er' => '0.00',
                    'withholding_tax' => '0.00',
                    'loan_deductions' => '0.00', 'other_deductions' => '0.00',
                    'adjustment_amount' => '0.00',
                    'total_deductions'  => '0.00',
                    'net_pay'           => $amount,
                    'computed_at'       => now(),
                ]);

                PayrollDeductionDetail::create([
                    'payroll_id'     => $payroll->id,
                    'deduction_type' => DeductionType::ThirteenthMonth->value,
                    'description'    => '13th Month Pay · '.$year,
                    'amount'         => $amount,
                ]);

                // The accrual is linked to its payroll row and its final amount
                // is recorded, but it is NOT marked paid here.
                //
                // computeAndPay() used to flip is_paid on a period still sitting
                // at Draft, so the year's 13th month read as settled before any
                // checker had seen it — and nothing downstream would ever pay it
                // again, since accrue() skips a paid accrual. Payment is now
                // recognised only when the period is finalized (see
                // markAccrualsPaidOnFinalize), which is the same maker-checker
                // gate REC-04 puts on every other material batch.
                $accrual->accrued_amount = $amount;
                $accrual->payroll_id     = $payroll->id;
                $accrual->save();

                // Stake the year's 13th-month cycle so a second run cannot pay
                // the same employee twice. Mirrors the guard the semi-monthly
                // calculator applies; the unique index is what enforces it.
                $this->claimThirteenthMonthCycle($payroll, $period, $emp);
            }

            // Computed, NOT Draft: rows exist and are awaiting approval. Parking
            // at Draft made a finished run indistinguishable from an untouched
            // one — the same conflation the status split removed for the
            // semi-monthly pipeline.
            $period->forceFill([
                'status'      => PayrollPeriodStatus::Computed->value,
                'computed_by' => $triggeredBy->id,
            ])->save();

            return $period->fresh();
        });
    }

    /**
     * Recognise payment once a 13th-month period is finalized.
     *
     * Called by the PayrollPeriodFinalized listener path so is_paid flips only
     * after approve() + finalize() have both run. Idempotent: an already-paid
     * accrual is left alone.
     */
    public function markAccrualsPaidOnFinalize(PayrollPeriod $period): int
    {
        if (! $period->is_thirteenth_month) {
            return 0;
        }

        $year = (int) $period->period_start->format('Y');
        $paidDate = $period->payroll_date ?? $period->period_end;

        return ThirteenthMonthAccrual::query()
            ->where('year', $year)
            ->where('is_paid', false)
            ->whereNotNull('payroll_id')
            ->whereIn('payroll_id', Payroll::where('payroll_period_id', $period->id)->select('id'))
            ->update([
                'is_paid'    => true,
                'paid_date'  => $paidDate instanceof \DateTimeInterface
                    ? $paidDate->format('Y-m-d')
                    : (string) $paidDate,
                'updated_at' => now(),
            ]);
    }

    /**
     * Claim the year's 13th-month cycle for this employee.
     *
     * The 13th month is its own cycle key ('YYYY-13TH'), so it never collides
     * with a semi-monthly cutoff. Two 13th-month runs for one year would, which
     * is exactly what this prevents.
     */
    private function claimThirteenthMonthCycle(Payroll $payroll, PayrollPeriod $period, Employee $employee): void
    {
        $cycleKey = $period->cycleKey();

        $holder = \App\Modules\Payroll\Models\PayrollCycleClaim::query()
            ->where('employee_id', $employee->id)
            ->where('cycle_key', $cycleKey)
            ->first();

        if ($holder) {
            // A re-run of the SAME period is legitimate: the wipe above deleted
            // the old payroll rows, and claims cascade on payroll_id, so any
            // surviving claim belongs to a different period.
            if ((int) $holder->payroll_period_id === (int) $period->id) {
                return;
            }

            throw new BusinessRuleException(sprintf(
                'Employee %s has already received 13th-month pay for %s under another period.',
                $employee->employee_no,
                $period->period_start->format('Y'),
            ));
        }

        \App\Modules\Payroll\Models\PayrollCycleClaim::create([
            'employee_id'       => $employee->id,
            'payroll_id'        => $payroll->id,
            'payroll_period_id' => $period->id,
            'cycle_key'         => $cycleKey,
        ]);
    }
}
