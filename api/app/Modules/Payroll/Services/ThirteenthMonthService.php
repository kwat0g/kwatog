<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

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
use RuntimeException;

/**
 * 13th-month pay tracking.
 *
 * Sprint 3 builds the accrual hook into PayrollCalculatorService.
 * Sprint 3 / Task 28 adds the December run (`computeAndPay`) that creates the
 * dedicated payroll period and disburses the accrued amount.
 */
class ThirteenthMonthService
{
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
            $payDate = $payrollDate ? CarbonImmutable::parse($payrollDate) : CarbonImmutable::create($year, 12, 15);

            // One 13th-month period per year.
            $existing = PayrollPeriod::query()
                ->where('is_thirteenth_month', true)
                ->whereYear('period_start', $year)
                ->first();

            if ($existing && $existing->status === PayrollPeriodStatus::Finalized) {
                throw new RuntimeException("13th-month period for {$year} is already finalized.");
            }

            $period = $existing ?? PayrollPeriod::create([
                'period_start'        => "{$year}-12-01",
                'period_end'          => "{$year}-12-31",
                'payroll_date'        => $payDate->toDateString(),
                'is_first_half'       => false,
                'is_thirteenth_month' => true,
                'status'              => PayrollPeriodStatus::Draft->value,
                'created_by'          => $triggeredBy->id,
            ]);

            // Wipe any partial run so this is idempotent.
            $oldPayrollIds = Payroll::where('payroll_period_id', $period->id)->pluck('id');
            PayrollDeductionDetail::whereIn('payroll_id', $oldPayrollIds)->delete();
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

                $accrual->accrued_amount = $amount;
                $accrual->is_paid        = true;
                $accrual->paid_date      = $payDate->toDateString();
                $accrual->payroll_id     = $payroll->id;
                $accrual->save();
            }

            return $period->fresh();
        });
    }
}
