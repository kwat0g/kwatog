<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Common\Support\Money;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\ThirteenthMonthAccrual;

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
}
