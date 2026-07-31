<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\PayType;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Employee;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sprint 8 — Task 71. Final pay calculator + JE poster.
 *
 * Components:
 *   + last_salary_pro_rated         (final period worked, computed pro-rata)
 *   + unused_convertible_leave_value (sum of leave_balances where convertible)
 *   + pro_rated_13th_month          (year-to-date basic / 12)
 *   - less_loan_balance             (sum of active employee_loans.balance)
 *   - less_unreturned_property      (employee_property where status='lost')
 *   - less_advance                  (open cash_advance balance)
 *   = net
 */
class FinalPayService
{
    public function __construct(
        private readonly JournalEntryService $journals,
        private readonly SettingsService $settings,
    ) {}

    public function compute(Clearance $clearance): Clearance
    {
        return DB::transaction(function () use ($clearance) {
            $clearance->load('employee');
            $employee = $clearance->employee;
            if (! $employee) throw new BusinessRuleException('Clearance has no employee.');

            $lastSalary = $this->lastSalaryProRated($employee, $clearance->separation_date);
            $leaveValue = $this->unusedConvertibleLeaveValue($employee);
            $thirteenth = $this->proRatedThirteenthMonth($employee, $clearance->separation_date);
            $loanBal    = $this->loanBalances($employee);
            $propertyL  = $this->unreturnedPropertyValue($employee);
            $advance    = $this->openCashAdvance($employee);

            $plus = $lastSalary + $leaveValue + $thirteenth;
            $less = $loanBal + $propertyL + $advance;
            $net  = max(0.0, $plus - $less);

            $breakdown = [
                'last_salary_pro_rated'           => number_format($lastSalary, 2, '.', ''),
                'unused_convertible_leave_value'  => number_format($leaveValue, 2, '.', ''),
                'pro_rated_13th_month'            => number_format($thirteenth, 2, '.', ''),
                'less_loan_balance'               => number_format($loanBal, 2, '.', ''),
                'less_unreturned_property_value'  => number_format($propertyL, 2, '.', ''),
                'less_advance'                    => number_format($advance, 2, '.', ''),
                'gross_plus'                      => number_format($plus, 2, '.', ''),
                'gross_less'                      => number_format($less, 2, '.', ''),
                'net'                             => number_format($net, 2, '.', ''),
            ];

            $clearance->forceFill([
                'final_pay_breakdown' => $breakdown,
                'final_pay_amount'    => number_format($net, 2, '.', ''),
                'final_pay_computed'  => true,
            ])->save();

            return $clearance->fresh();
        });
    }

    /**
     * Post a final-pay JE:
     *   DR Salaries Expense (last_salary_pro_rated)
     *   DR 13th Month Expense (pro_rated_13th_month)
     *   DR Salaries Expense (unused_convertible_leave_value)  [proxied to Salaries]
     *   CR Cash in Bank (net)
     *   CR Loans Receivable / Advances Receivable / etc are already on the books;
     *     we offset by reducing 'Loans Payable' / 'Accrued Expenses' lines if applicable.
     *
     * For simplicity we book:
     *   DR Salaries Expense          (gross_plus)
     *   CR Loans Payable             (less_loan_balance)        — if > 0
     *   CR Accrued Expenses          (less_advance + less_unreturned_property)
     *   CR Cash in Bank              (net)
     */
    public function postJournalEntry(Clearance $clearance, User $by): JournalEntry
    {
        if (! $clearance->final_pay_computed || ! $clearance->final_pay_amount) {
            throw new RuntimeException('Compute final pay before posting JE.');
        }
        $b = $clearance->final_pay_breakdown ?? [];
        $plus = (float) ($b['gross_plus'] ?? 0);
        $less = (float) ($b['gross_less'] ?? 0);
        $net  = (float) ($b['net']        ?? 0);

        $loan      = (float) ($b['less_loan_balance'] ?? 0);
        $advance   = (float) ($b['less_advance']      ?? 0);
        $property  = (float) ($b['less_unreturned_property_value'] ?? 0);

        $salariesExp = Account::where('code', '6010')->orWhere('code', '5050')->orderBy('code')->firstOrFail();
        $cashInBank  = Account::where('code', '1020')->firstOrFail();
        $loansPayable= Account::where('code', '2100')->firstOrFail();
        $accrued     = Account::where('code', '2070')->firstOrFail();

        $lines = [
            ['account_id' => $salariesExp->id, 'debit' => number_format($plus, 2, '.', ''), 'credit' => '0.00', 'description' => 'Final pay components'],
        ];
        if ($loan > 0) {
            $lines[] = ['account_id' => $loansPayable->id, 'debit' => '0.00', 'credit' => number_format($loan, 2, '.', ''), 'description' => 'Settle outstanding loan from final pay'];
        }
        if (($advance + $property) > 0) {
            $lines[] = ['account_id' => $accrued->id, 'debit' => '0.00', 'credit' => number_format($advance + $property, 2, '.', ''), 'description' => 'Settle advance / unreturned property'];
        }
        // Only add the cash disbursement line when net > 0. When deductions exactly cancel
        // earnings (net = 0.00) the JournalEntryService rejects a line with both debit and
        // credit equal to zero ("exactly one of debit or credit greater than zero").
        if ($net > 0) {
            $lines[] = ['account_id' => $cashInBank->id, 'debit' => '0.00', 'credit' => number_format($net, 2, '.', ''), 'description' => 'Final pay disbursement'];
        }

        $je = $this->journals->create([
            'date'           => $clearance->separation_date->toDateString(),
            'description'    => 'Final pay — '.$clearance->clearance_no,
            'reference_type' => Clearance::class,
            'reference_id'   => $clearance->id,
            'lines'          => $lines,
        ], $by);
        return $this->journals->post($je, $by);
    }

    /* ─── Component helpers ─── */

    private function lastSalaryProRated(Employee $e, CarbonInterface $separationDate): float
    {
        $period = PayrollPeriod::query()
            ->where('is_thirteenth_month', false)
            ->whereDate('period_start', '<=', $separationDate->toDateString())
            ->whereDate('period_end', '>=', $separationDate->toDateString())
            ->where('status', '!=', PayrollPeriodStatus::Voided->value)
            ->orderByDesc('period_start')
            ->first();

        // A disbursed period has already been paid and must never be included
        // again in final pay.
        if (! $period || $period->status === PayrollPeriodStatus::Disbursed) {
            return 0.0;
        }

        // Prefer the authoritative result of the payroll engine when the open
        // period has already been computed for this employee.
        $payroll = Payroll::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $e->id)
            ->whereNotNull('computed_at')
            ->first();
        if ($payroll) {
            return max(0.0,
                (float) $payroll->basic_pay
                + (float) $payroll->leave_pay
                - (float) $payroll->tardiness_deduction
                - (float) $payroll->undertime_deduction
            );
        }

        // Otherwise calculate only from persisted DTR hours in the real
        // payroll period. Each row contributes at most one eight-hour day;
        // half-days contribute 0.5. No synthetic attendance is invented.
        $dayEquivalents = Attendance::query()
            ->where('employee_id', $e->id)
            ->whereBetween('date', [$period->period_start, $separationDate])
            ->get(['regular_hours'])
            ->sum(fn (Attendance $attendance): float => min(1.0, max(0.0, (float) $attendance->regular_hours / $this->hoursPerDay())));

        $dailyRate = $e->pay_type === PayType::Daily
            ? (float) ($e->daily_rate ?? 0)
            : (float) ($e->basic_monthly_salary ?? 0) / $this->workDaysPerMonth();

        return max(0.0, round($dayEquivalents * $dailyRate, 2));
    }

    private function unusedConvertibleLeaveValue(Employee $e): float
    {
        // Conservative fallback when leave module tables are unreachable: 0.
        try {
            $rows = DB::table('employee_leave_balances as elb')
                ->join('leave_types as lt', 'elb.leave_type_id', '=', 'lt.id')
                ->where('elb.employee_id', $e->id)
                ->where('lt.is_convertible_on_separation', true)
                ->select(DB::raw('SUM(elb.remaining * lt.conversion_rate) as v'))
                ->value('v');
            $days = (float) ($rows ?? 0);
        } catch (\Throwable $ex) {
            $days = 0.0;
        }

        $rate = (float) ($e->daily_rate ?: ((float) ($e->basic_monthly_salary ?? 0) / $this->workDaysPerMonth()));
        return max(0.0, $days * $rate);
    }

    private function workDaysPerMonth(): float
    {
        return $this->positivePayrollSetting('payroll.work_days_per_month');
    }

    private function hoursPerDay(): float
    {
        return $this->positivePayrollSetting('payroll.hours_per_day');
    }

    private function positivePayrollSetting(string $key): float
    {
        $value = $this->settings->get($key);
        if (! is_numeric($value) || (float) $value <= 0) {
            throw new BusinessRuleException("Required payroll setting {$key} is missing or invalid.");
        }
        return (float) $value;
    }

    private function proRatedThirteenthMonth(Employee $e, CarbonInterface $separationDate): float
    {
        $year = (int) $separationDate->format('Y');
        $row = DB::table('thirteenth_month_accruals')
            ->where('employee_id', $e->id)
            ->where('year', $year)
            ->orderByDesc('id')
            ->first();
        if ($row) {
            return (float) $row->accrued_amount;
        }

        // Rebuild from authoritative computed payroll when an accrual snapshot
        // has not been generated yet. No salary-based synthetic month is added.
        $basicEarned = DB::table('payrolls as p')
            ->join('payroll_periods as pp', 'pp.id', '=', 'p.payroll_period_id')
            ->where('p.employee_id', $e->id)
            ->whereYear('pp.period_end', $year)
            ->whereDate('pp.period_end', '<=', $separationDate->toDateString())
            ->whereNotIn('pp.status', [PayrollPeriodStatus::Draft->value, PayrollPeriodStatus::Processing->value, PayrollPeriodStatus::Voided->value])
            ->whereNotNull('p.computed_at')
            ->sum('p.basic_pay');

        return round((float) $basicEarned / 12, 2);
    }

    private function loanBalances(Employee $e): float
    {
        try {
            return (float) DB::table('employee_loans')
                ->where('employee_id', $e->id)
                ->whereIn('status', ['active', 'pending'])
                ->where('loan_type', 'company_loan')
                ->sum('balance');
        } catch (\Throwable $ex) {
            return 0.0;
        }
    }

    private function openCashAdvance(Employee $e): float
    {
        try {
            return (float) DB::table('employee_loans')
                ->where('employee_id', $e->id)
                ->whereIn('status', ['active', 'pending'])
                ->where('loan_type', 'cash_advance')
                ->sum('balance');
        } catch (\Throwable $ex) {
            return 0.0;
        }
    }

    private function unreturnedPropertyValue(Employee $e): float
    {
        try {
            return (float) DB::table('employee_property')
                ->where('employee_id', $e->id)
                ->where('status', 'lost')
                ->sum(DB::raw('quantity * replacement_unit_cost'));
        } catch (\Throwable $ex) {
            return 0.0;
        }
    }
}
