<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\AuditLog;
use App\Common\Services\SettingsService;
use App\Common\Support\Money;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Employee;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

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

    public function compute(Clearance $clearance, ?User $by = null): Clearance
    {
        return DB::transaction(function () use ($clearance, $by) {
            // Final pay is later consumed by finalize(), which locks this
            // aggregate before posting money. Re-read and lock here too so a
            // compute request racing with finalization cannot overwrite the
            // authoritative breakdown after the clearance becomes terminal.
            $lockedClearance = Clearance::query()
                ->lockForUpdate()
                ->find($clearance->id);

            if (! $lockedClearance) {
                throw new BusinessRuleException('Clearance not found.');
            }
            if ($lockedClearance->status?->isTerminal()) {
                throw new BusinessRuleException('Final pay cannot be recomputed for a closed clearance.');
            }

            $lockedClearance->load('employee');
            $employee = $lockedClearance->employee;
            if (! $employee) throw new BusinessRuleException('Clearance has no employee.');

            $lastSalary = $this->lastSalaryProRated($employee, $lockedClearance->separation_date);
            $leaveValue = $this->unusedConvertibleLeaveValue($employee);
            $thirteenth = $this->proRatedThirteenthMonth($employee, $lockedClearance->separation_date);
            $loanBal    = $this->loanBalances($employee);
            $propertyL  = $this->unreturnedPropertyValue($employee);
            $advance    = $this->openCashAdvance($employee);

            $plus = Money::add($lastSalary, $leaveValue, $thirteenth);
            $less = Money::add($loanBal, $propertyL, $advance);
            $net  = Money::clampMin(Money::sub($plus, $less), Money::zero());

            $breakdown = [
                'last_salary_pro_rated'           => $lastSalary,
                'unused_convertible_leave_value'  => $leaveValue,
                'pro_rated_13th_month'            => $thirteenth,
                'less_loan_balance'               => $loanBal,
                'less_unreturned_property_value'  => $propertyL,
                'less_advance'                    => $advance,
                'gross_plus'                      => $plus,
                'gross_less'                      => $less,
                'net'                             => $net,
            ];

            $previous = [
                'final_pay_computed' => (bool) $lockedClearance->final_pay_computed,
                'final_pay_amount'   => $lockedClearance->final_pay_amount,
            ];

            $lockedClearance->forceFill([
                'final_pay_breakdown' => $breakdown,
                'final_pay_amount'    => $net,
                'final_pay_computed'  => true,
            ])->save();

            // P05 advisory — the breakdown every later JE spends must be
            // attributed, mirroring finalize's attribution.
            AuditLog::create([
                'user_id'    => $by?->id,
                'action'     => 'hr.clearance.final_pay_computed',
                'model_type' => Clearance::class,
                'model_id'   => $lockedClearance->id,
                'old_values' => $previous,
                'new_values' => [
                    'final_pay_computed' => true,
                    'final_pay_amount'   => $net,
                    'computed_by'        => $by?->id,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'created_at' => now(),
            ]);

            return $lockedClearance->fresh();
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
        return DB::transaction(function () use ($clearance, $by): JournalEntry {
            // The clearance is the source-of-truth lock for this accounting
            // side effect. This makes direct/replayed service calls converge
            // on one journal entry even when the caller is outside
            // SeparationService::finalize().
            $lockedClearance = Clearance::query()
                ->lockForUpdate()
                ->find($clearance->id);

            if (! $lockedClearance) {
                throw new BusinessRuleException('Clearance not found.');
            }
            if (! $lockedClearance->final_pay_computed || ! $lockedClearance->final_pay_amount) {
                throw new RuntimeException('Compute final pay before posting JE.');
            }

            $existing = null;
            if ($lockedClearance->journal_entry_id) {
                $existing = JournalEntry::query()
                    ->lockForUpdate()
                    ->find($lockedClearance->journal_entry_id);

                if ($existing && ($existing->reference_type !== Clearance::class
                    || (int) $existing->reference_id !== (int) $lockedClearance->id)) {
                    throw new BusinessRuleException('Clearance is linked to an unrelated journal entry.');
                }
            }

            // Recover a draft/posted entry created before the clearance link
            // was persisted, such as after a worker/request interruption.
            $existing ??= JournalEntry::query()
                ->where('reference_type', Clearance::class)
                ->where('reference_id', $lockedClearance->id)
                ->whereIn('status', [JournalEntryStatus::Draft, JournalEntryStatus::Posted])
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($existing) {
                if ($existing->status === JournalEntryStatus::Reversed) {
                    throw new BusinessRuleException(
                        'The final-pay journal entry has been reversed; resolve it manually before retrying final pay.'
                    );
                }

                $posted = $existing->status === JournalEntryStatus::Draft
                    ? $this->journals->post($existing, $by)
                    : $existing->fresh(['lines.account']);

                $lockedClearance->forceFill(['journal_entry_id' => $posted->id])->save();

                return $posted;
            }

            $b = $lockedClearance->final_pay_breakdown ?? [];

            // P05-02 — the breakdown is a snapshot from compute time. Re-read
            // the deduction side against live sources at the money moment:
            // finalize's loan gate already refused any still-outstanding loan,
            // so a loan settled between compute and finalize must not be
            // deducted twice. Earnings (gross_plus) stay as computed — the
            // operator-approved base — but what is withheld is re-derived and
            // persisted so the UI and the JE agree.
            $lockedClearance->load('employee');
            $liveEmployee  = $lockedClearance->employee;
            $liveLoan      = $liveEmployee ? $this->loanBalances($liveEmployee) : Money::zero();
            $liveAdvance   = $liveEmployee ? $this->openCashAdvance($liveEmployee) : Money::zero();
            $liveProperty  = $liveEmployee ? $this->unreturnedPropertyValue($liveEmployee) : Money::zero();

            $plus = Money::round2((string) ($b['gross_plus'] ?? Money::zero()));
            $less = Money::add($liveLoan, $liveAdvance, $liveProperty);
            $net  = Money::clampMin(Money::sub($plus, $less), Money::zero());

            $b['less_loan_balance']              = $liveLoan;
            $b['less_advance']                   = $liveAdvance;
            $b['less_unreturned_property_value'] = $liveProperty;
            $b['gross_less']                     = $less;
            $b['net']                            = $net;
            $lockedClearance->forceFill([
                'final_pay_breakdown' => $b,
                'final_pay_amount'    => $net,
            ])->save();

            $loan = $liveLoan;

            $salariesExp = Account::where('code', $this->settings->requiredString('accounting.accounts.final_pay_salary_expense_code'))->firstOrFail();
            $cashInBank  = Account::where('code', $this->settings->requiredString('accounting.accounts.cash_code'))->firstOrFail();
            $loansPayable= Account::where('code', $this->settings->requiredString('accounting.accounts.loans_payable_code'))->firstOrFail();
            $accrued     = Account::where('code', $this->settings->requiredString('accounting.accounts.accrued_expense_code'))->firstOrFail();

            // P05-01 — when deductions exceed earnings, net is clamped at 0.00
            // but crediting the full deductions would leave an unbalanced JE
            // that 500s the separation. Only what this payout can actually
            // absorb is recovered; the un-recovered remainder stays on the
            // books as an outstanding loan / property liability.
            $recoverable = Money::lt($plus, $less) ? $plus : $less;
            $loanCredit  = Money::lt($loan, $recoverable) ? $loan : $recoverable;
            $otherCredit = Money::sub($recoverable, $loanCredit);

            $lines = [
                ['account_id' => $salariesExp->id, 'debit' => $plus, 'credit' => Money::zero(), 'description' => 'Final pay components'],
            ];
            if (Money::gt($loanCredit, Money::zero())) {
                $lines[] = ['account_id' => $loansPayable->id, 'debit' => Money::zero(), 'credit' => $loanCredit, 'description' => 'Settle outstanding loan from final pay'];
            }
            if (Money::gt($otherCredit, Money::zero())) {
                $lines[] = ['account_id' => $accrued->id, 'debit' => Money::zero(), 'credit' => $otherCredit, 'description' => 'Settle advance / unreturned property'];
            }
            // Only add the cash disbursement line when net > 0. When deductions
            // exactly cancel earnings (net = 0.00), the journal validator
            // rejects a line with both debit and credit equal to zero.
            if (Money::gt($net, Money::zero())) {
                $lines[] = ['account_id' => $cashInBank->id, 'debit' => Money::zero(), 'credit' => $net, 'description' => 'Final pay disbursement'];
            }

            $je = $this->journals->create([
                'date'           => $lockedClearance->separation_date->toDateString(),
                'description'    => 'Final pay — '.$lockedClearance->clearance_no,
                'reference_type' => Clearance::class,
                'reference_id'   => $lockedClearance->id,
                'lines'          => $lines,
            ], $by);
            $posted = $this->journals->post($je, $by);
            $lockedClearance->forceFill(['journal_entry_id' => $posted->id])->save();

            return $posted;
        });
    }

    /* ─── Component helpers ─── */

    private function lastSalaryProRated(Employee $e, CarbonInterface $separationDate): string
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
            return Money::zero();
        }

        // Prefer the authoritative result of the payroll engine when the open
        // period has already been computed for this employee.
        $payroll = Payroll::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $e->id)
            ->whereNotNull('computed_at')
            ->first();
        if ($payroll) {
            $earnings = Money::add((string) $payroll->basic_pay, (string) $payroll->leave_pay);
            $deductions = Money::add((string) $payroll->tardiness_deduction, (string) $payroll->undertime_deduction);

            return Money::clampMin(Money::sub($earnings, $deductions), Money::zero());
        }

        // Otherwise calculate only from persisted DTR hours in the real
        // payroll period. Each row contributes at most one eight-hour day;
        // half-days contribute 0.5. No synthetic attendance is invented.
        $dayEquivalents = '0.0000';
        $hoursPerDay = $this->hoursPerDay();
        $attendances = Attendance::query()
            ->where('employee_id', $e->id)
            ->whereBetween('date', [$period->period_start, $separationDate])
            ->get(['regular_hours']);
        foreach ($attendances as $attendance) {
            $fraction = bcdiv((string) $attendance->regular_hours, $hoursPerDay, Money::INNER);
            $fraction = bccomp($fraction, '0', Money::INNER) < 0 ? '0.0000' : $fraction;
            $fraction = bccomp($fraction, '1', Money::INNER) > 0 ? '1.0000' : $fraction;
            $dayEquivalents = bcadd($dayEquivalents, $fraction, Money::INNER);
        }

        $dailyRate = $this->authoritativeDailyRate($e);

        return Money::clampMin(Money::mul($dayEquivalents, $dailyRate), Money::zero());
    }

    private function unusedConvertibleLeaveValue(Employee $e): string
    {
        // A missing/failed leave query must not silently become zero final pay.
        // Database errors propagate so the separation remains visibly pending
        // until the authoritative leave data source is available.
        $rows = DB::table('employee_leave_balances as elb')
            ->join('leave_types as lt', 'elb.leave_type_id', '=', 'lt.id')
            ->where('elb.employee_id', $e->id)
            ->where('lt.is_convertible_on_separation', true)
            ->select(DB::raw('SUM(elb.remaining * lt.conversion_rate) as v'))
            ->value('v');
        $days = (string) ($rows ?? '0');

        $rate = $this->authoritativeDailyRate($e);
        return Money::clampMin(Money::mul($days, $rate), Money::zero());
    }

    private function authoritativeDailyRate(Employee $employee): string
    {
        // Monthly equivalent reconciles both pay types (see Employee model), so
        // a semi-monthly employee's separation pay is not computed off half a
        // month's figure.
        $monthly = $employee->monthlyEquivalentSalary();
        $rate = $monthly !== null
            ? Money::div((string) $monthly, $this->workDaysPerMonth(), Money::INNER)
            : '0.0000';

        if (bccomp($rate, '0', Money::INNER) <= 0) {
            throw new BusinessRuleException("Employee {$employee->employee_no} has no authoritative pay rate for final-pay calculation.");
        }

        return $rate;
    }

    private function workDaysPerMonth(): string
    {
        return $this->positivePayrollSetting('payroll.work_days_per_month');
    }

    private function hoursPerDay(): string
    {
        return $this->positivePayrollSetting('payroll.hours_per_day');
    }

    private function positivePayrollSetting(string $key): string
    {
        $value = $this->settings->get($key);
        if (! is_numeric($value) || bccomp((string) $value, '0', Money::INNER) <= 0) {
            throw new BusinessRuleException("Required payroll setting {$key} is missing or invalid.");
        }
        return bcadd((string) $value, '0', Money::INNER);
    }

    private function proRatedThirteenthMonth(Employee $e, CarbonInterface $separationDate): string
    {
        $year = (int) $separationDate->format('Y');
        $row = DB::table('thirteenth_month_accruals')
            ->where('employee_id', $e->id)
            ->where('year', $year)
            ->orderByDesc('id')
            ->first();
        if ($row) {
            return Money::round2((string) $row->accrued_amount);
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

        return Money::round2(Money::div((string) $basicEarned, '12', Money::INNER));
    }

    private function loanBalances(Employee $e): string
    {
        return $this->readFinalPaySource('employee loan balances', fn (): string => (string) DB::table('employee_loans')
                ->where('employee_id', $e->id)
                ->whereIn('status', ['active', 'pending'])
                ->where('loan_type', 'company_loan')
                ->sum('balance'));
    }

    private function openCashAdvance(Employee $e): string
    {
        return $this->readFinalPaySource('employee cash-advance balances', fn (): string => (string) DB::table('employee_loans')
                ->where('employee_id', $e->id)
                ->whereIn('status', ['active', 'pending'])
                ->where('loan_type', 'cash_advance')
                ->sum('balance'));
    }

    private function unreturnedPropertyValue(Employee $e): string
    {
        return $this->readFinalPaySource('employee property records', fn (): string => (string) DB::table('employee_property')
                ->where('employee_id', $e->id)
                ->where('status', 'lost')
                ->sum(DB::raw('quantity * replacement_unit_cost')));
    }

    /**
     * A missing or unavailable cross-module source must block final-pay
     * computation. Treating a failed loan/property read as zero would create a
     * valid-looking but financially incomplete clearance.
     */
    private function readFinalPaySource(string $source, Closure $query): string
    {
        try {
            return Money::round2($query());
        } catch (Throwable $exception) {
            report($exception);

            throw new BusinessRuleException(
                "Final pay cannot be computed because {$source} are unavailable. Resolve the source data and retry."
            );
        }
    }
}
