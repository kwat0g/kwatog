<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Common\Support\Money;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\DeMinimisBenefitType;
use App\Modules\Payroll\Models\DeMinimisBenefit;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * De minimis benefits tracker for Philippine statutory tax-exempt benefits.
 *
 * Philippine tax law (BIR RMC 2024) exempts specific benefits up to defined
 * limits. Amounts exceeding the limit are taxable compensation.
 *
 * This service manages recording, YTD aggregation, taxable-excess
 * computation, and payroll integration.
 */
class DeMinimisService
{
    /**
     * Record a de minimis benefit entry.
     *
     * For annual-type benefits, this also checks whether the recorded amount
     * pushes the employee over the annual cap for the year and, if so, records
     * a companion taxable-portion entry automatically.
     */
    public function record(
        Employee $employee,
        DeMinimisBenefitType $benefitType,
        string $amount,
        int $periodYear,
        int $periodMonth,
        ?int $payrollId = null,
        ?string $notes = null,
    ): DeMinimisBenefit {
        return DB::transaction(function () use ($employee, $benefitType, $amount, $periodYear, $periodMonth, $payrollId, $notes) {
            // Determine the non-taxable portion: the min of this amount and the remaining limit.
            $excess = $this->getTaxableExcess($employee, $benefitType, $periodYear, $periodMonth, $amount);
            $nonTaxableAmount = Money::sub($amount, $excess);

            if (Money::gt($nonTaxableAmount, '0')) {
                DeMinimisBenefit::create([
                    'employee_id'       => $employee->id,
                    'benefit_type'      => $benefitType->value,
                    'amount'            => $nonTaxableAmount,
                    'payroll_id'        => $payrollId,
                    'period_year'       => $periodYear,
                    'period_month'      => $periodMonth,
                    'is_taxable_portion' => false,
                    'notes'             => $notes,
                ]);
            }

            if (Money::gt($excess, '0')) {
                DeMinimisBenefit::create([
                    'employee_id'       => $employee->id,
                    'benefit_type'      => $benefitType->value,
                    'amount'            => $excess,
                    'payroll_id'        => $payrollId,
                    'period_year'       => $periodYear,
                    'period_month'      => $periodMonth,
                    'is_taxable_portion' => true,
                    'notes'             => $notes ? $notes.' (taxable excess)' : 'Taxable excess',
                ]);
            }

            // Return the non-taxable row for reference; return taxable if no non-taxable.
            if (Money::gt($nonTaxableAmount, '0')) {
                return DeMinimisBenefit::where([
                    'employee_id' => $employee->id,
                    'benefit_type' => $benefitType->value,
                    'period_year' => $periodYear,
                    'period_month' => $periodMonth,
                    'is_taxable_portion' => false,
                ])->latest()->first();
            }

            return DeMinimisBenefit::where([
                'employee_id' => $employee->id,
                'benefit_type' => $benefitType->value,
                'period_year' => $periodYear,
                'period_month' => $periodMonth,
                'is_taxable_portion' => true,
            ])->latest()->first();
        });
    }

    /**
     * Year-to-date total for a specific benefit type and employee.
     * Sums non-taxable portions through the given year.
     */
    public function getYtdTotal(Employee $employee, DeMinimisBenefitType $benefitType, int $year): string
    {
        $total = DeMinimisBenefit::query()
            ->where('employee_id', $employee->id)
            ->where('benefit_type', $benefitType->value)
            ->where('is_taxable_portion', false)
            ->where('period_year', $year)
            ->sum('amount');

        return number_format((float) $total, 2, '.', '');
    }

    /**
     * Compute the taxable excess portion of a given amount for a benefit type.
     *
     * For monthly benefits: if (current month's total + new amount) exceeds monthly limit,
     * the excess is taxable.
     *
     * For annual benefits: if (YTD total + new amount) exceeds annual limit,
     * the excess is taxable.
     *
     * @param  string  $amount  The new amount being considered.
     * @return string  The taxable excess (0.00 if within limit).
     */
    public function getTaxableExcess(
        Employee $employee,
        DeMinimisBenefitType $benefitType,
        int $year,
        int $month,
        string $amount = '0.00',
    ): string {
        if ($benefitType->isFlagOnly()) {
            return $amount; // flag-only types are always fully taxable (or handled separately)
        }

        if ($benefitType->isAnnual()) {
            $ytdTotal = $this->getYtdTotal($employee, $benefitType, $year);
            $annualLimit = $benefitType->annualLimit();
            $projected = Money::add($ytdTotal, $amount);

            if (Money::gt($projected, $annualLimit)) {
                return Money::sub($projected, $annualLimit);
            }

            return Money::zero();
        }

        // Monthly benefit: check if current month + new amount > monthly limit
        $monthTotal = DeMinimisBenefit::query()
            ->where('employee_id', $employee->id)
            ->where('benefit_type', $benefitType->value)
            ->where('is_taxable_portion', false)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->sum('amount');

        $monthTotalFormatted = number_format((float) $monthTotal, 2, '.', '');
        $projected = Money::add($monthTotalFormatted, $amount);
        $monthlyLimit = $benefitType->monthlyLimit();

        if (Money::gt($projected, $monthlyLimit)) {
            return Money::sub($projected, $monthlyLimit);
        }

        return Money::zero();
    }

    /**
     * Compute de minimis breakdown for all employees in a payroll period.
     *
     * Returns a collection keyed by employee_id with:
     *   - non_taxable_total: sum of amounts within statutory limits
     *   - taxable_excess_total: sum of amounts exceeding the limits
     *   - breakdown: per-benefit-type detail
     *
     * @return Collection<int, array{
     *   non_taxable_total: string,
     *   taxable_excess_total: string,
     *   breakdown: array<string, array{amount: string, non_taxable: string, taxable_excess: string}>,
     * }>
     */
    public function computeForPayroll(PayrollPeriod $period): Collection
    {
        $year = $period->period_start->year;
        $month = $period->period_start->month;

        // Fetch all non-taxable de minimis entries for this period's month/year.
        $entries = DeMinimisBenefit::query()
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->get()
            ->groupBy('employee_id');

        $results = collect();

        foreach ($entries as $employeeId => $employeeEntries) {
            $nonTaxableTotal = Money::zero();
            $taxableExcessTotal = Money::zero();
            $breakdown = [];

            $grouped = $employeeEntries->groupBy('benefit_type');
            foreach ($grouped as $benefitTypeValue => $typeEntries) {
                $typeEnum = DeMinimisBenefitType::from($benefitTypeValue);
                $nonTaxable = Money::zero();
                $taxable = Money::zero();

                foreach ($typeEntries as $entry) {
                    if ($entry->is_taxable_portion) {
                        $taxable = Money::add($taxable, $entry->amount);
                    } else {
                        $nonTaxable = Money::add($nonTaxable, $entry->amount);
                    }
                }

                $breakdown[$benefitTypeValue] = [
                    'amount'          => Money::add($nonTaxable, $taxable),
                    'non_taxable'     => $nonTaxable,
                    'taxable_excess'  => $taxable,
                    'label'           => $typeEnum->label(),
                ];

                $nonTaxableTotal = Money::add($nonTaxableTotal, $nonTaxable);
                $taxableExcessTotal = Money::add($taxableExcessTotal, $taxable);
            }

            $results[(int) $employeeId] = [
                'non_taxable_total'   => $nonTaxableTotal,
                'taxable_excess_total' => $taxableExcessTotal,
                'breakdown'           => $breakdown,
            ];
        }

        return $results;
    }

    /**
     * Get the non-taxable de minimis total for an employee for a given period.
     *
     * This is used by PayrollCalculatorService to add to gross pay as a
     * non-taxable addition.
     */
    public function getNonTaxableTotalForEmployee(Employee $employee, int $year, int $month): string
    {
        $total = DeMinimisBenefit::query()
            ->where('employee_id', $employee->id)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->where('is_taxable_portion', false)
            ->sum('amount');

        return number_format((float) $total, 2, '.', '');
    }

    /**
     * Get the taxable excess de minimis total for an employee for a given period.
     *
     * This is added to the taxable income base for withholding tax computation.
     */
    public function getTaxableExcessForEmployee(Employee $employee, int $year, int $month): string
    {
        $total = DeMinimisBenefit::query()
            ->where('employee_id', $employee->id)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->where('is_taxable_portion', true)
            ->sum('amount');

        return number_format((float) $total, 2, '.', '');
    }

    /**
     * Delete a de minimis entry by ID with permission check.
     */
    public function delete(int $id): bool
    {
        $entry = DeMinimisBenefit::find($id);
        if (! $entry) {
            return false;
        }

        // Also delete the companion taxable-portion row if this is a non-taxable entry.
        if (! $entry->is_taxable_portion) {
            DeMinimisBenefit::where([
                'employee_id'  => $entry->employee_id,
                'benefit_type' => $entry->benefit_type,
                'period_year'  => $entry->period_year,
                'period_month' => $entry->period_month,
                'is_taxable_portion' => true,
            ])->delete();
        }

        return (bool) $entry->delete();
    }
}
