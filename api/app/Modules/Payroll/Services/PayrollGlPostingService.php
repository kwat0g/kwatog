<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentSequenceService;
use App\Common\Services\SettingsService;
use App\Common\Support\Money;
use App\Modules\Accounting\Services\AccountingPeriodService;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Posts a finalized payroll period to the General Ledger as a balanced JE.
 *
 * Idempotency: bails if the period already has a journal_entry_id.
 *
 * Feature flag: gated behind `modules.accounting`. If accounting is disabled
 * (Sprint 4 not yet shipped, or the company hasn't activated it), we skip
 * gracefully and log an audit-friendly message. The period stays finalized;
 * a backfill command can post later when accounting is turned on.
 */
class PayrollGlPostingService
{
    public function __construct(
        private readonly DocumentSequenceService $sequences,
        private readonly SettingsService $settings,
        private readonly AccountingPeriodService $periods,
    ) {}

    /**
     * Posts the period's totals to the GL. Returns the journal_entry id (or null
     * when skipped/disabled).
     */
    public function post(PayrollPeriod $period): ?int
    {
        if ($period->status !== PayrollPeriodStatus::Finalized) {
            throw new BusinessRuleException('Only finalized periods can be posted to the GL.');
        }
        if ($period->journal_entry_id) {
            // Already posted — idempotent.
            return (int) $period->journal_entry_id;
        }

        // Feature flag check.
        $accountingEnabled = $this->settings->requiredBool('modules.accounting');
        if (! $accountingEnabled) {
            Log::info('PayrollGlPostingService: accounting module disabled; skipping GL post', [
                'period_id' => $period->id,
            ]);
            return null;
        }

        if (! Schema::hasTable('journal_entries') || ! Schema::hasTable('accounts')) {
            Log::warning('PayrollGlPostingService: journal_entries / accounts table missing; skipping');
            return null;
        }

        // OGAMI-001 — block posting payroll into a closed accounting period.
        // Guard the payroll_date (the GL date used for the JE below); fall back
        // to period_end if payroll_date is somehow unset.
        $this->periods->assertPostingAllowed($period->payroll_date ?? $period->period_end);

        return DB::transaction(function () use ($period) {
            // Aggregate totals from payroll rows.
            $totals = DB::table('payrolls')
                ->where('payroll_period_id', $period->id)
                ->whereNull('error_message')
                ->selectRaw('
                    COALESCE(SUM(basic_pay),       0) as basic,
                    COALESCE(SUM(leave_pay),       0) as leave_pay,
                    COALESCE(SUM(overtime_pay),    0) as overtime,
                    COALESCE(SUM(night_diff_pay),  0) as night_diff,
                    COALESCE(SUM(holiday_pay),     0) as holiday,
                    COALESCE(SUM(tardiness_deduction), 0) as tardiness,
                    COALESCE(SUM(undertime_deduction), 0) as undertime,
                    COALESCE(SUM(adjustment_amount),   0) as adjustments,
                    COALESCE(SUM(sss_ee),          0) as sss_ee,
                    COALESCE(SUM(sss_er),          0) as sss_er,
                    COALESCE(SUM(philhealth_ee),   0) as ph_ee,
                    COALESCE(SUM(philhealth_er),   0) as ph_er,
                    COALESCE(SUM(pagibig_ee),      0) as pg_ee,
                    COALESCE(SUM(pagibig_er),      0) as pg_er,
                    COALESCE(SUM(withholding_tax), 0) as wht,
                    COALESCE(SUM(loan_deductions), 0) as loans,
                    COALESCE(SUM(gross_pay),       0) as gross,
                    COALESCE(SUM(net_pay),         0) as net
                ')
                ->first();

            $isThirteenth = (bool) $period->is_thirteenth_month;

            // Lookup account ids.
            $accountKeys = [
                'cash' => 'accounting.accounts.payroll_cash_code', 'sss_payable' => 'accounting.accounts.sss_payable_code',
                'philhealth_payable' => 'accounting.accounts.philhealth_payable_code', 'pagibig_payable' => 'accounting.accounts.pagibig_payable_code',
                'withholding_payable' => 'accounting.accounts.withholding_tax_payable_code', 'thirteenth_payable' => 'accounting.accounts.thirteenth_month_payable_code',
                'loans_payable' => 'accounting.accounts.loans_payable_code', 'salary_expense' => 'accounting.accounts.salary_expense_code',
                'overtime_expense' => 'accounting.accounts.overtime_expense_code', 'thirteenth_expense' => 'accounting.accounts.thirteenth_month_expense_code',
                'sss_employer_expense' => 'accounting.accounts.sss_employer_expense_code', 'philhealth_employer_expense' => 'accounting.accounts.philhealth_employer_expense_code',
                'pagibig_employer_expense' => 'accounting.accounts.pagibig_employer_expense_code',
            ];
            $codes = array_map(fn (string $key) => $this->settings->requiredString($key), $accountKeys);
            $accounts = DB::table('accounts')->whereIn('code', array_values($codes))->pluck('id', 'code');
            $code = fn (string $name): string => $codes[$name];

            // Build journal lines.
            $lines = [];
            $totalDebit = '0.00'; $totalCredit = '0.00';
            $lineNo = 1;

            $debit = function (string $code, string $amount, string $desc) use (&$lines, &$totalDebit, &$lineNo, $accounts) {
                if (Money::isZero($amount) || ! isset($accounts[$code])) return;
                $lines[] = [
                    'account_id' => $accounts[$code],
                    'line_no'    => $lineNo++,
                    'debit'      => $amount,
                    'credit'     => '0.00',
                    'description' => $desc,
                ];
                $totalDebit = Money::add($totalDebit, $amount);
            };
            $credit = function (string $code, string $amount, string $desc) use (&$lines, &$totalCredit, &$lineNo, $accounts) {
                if (Money::isZero($amount) || ! isset($accounts[$code])) return;
                $lines[] = [
                    'account_id' => $accounts[$code],
                    'line_no'    => $lineNo++,
                    'debit'      => '0.00',
                    'credit'     => $amount,
                    'description' => $desc,
                ];
                $totalCredit = Money::add($totalCredit, $amount);
            };

            $basicLine    = Money::add((string) $totals->basic, (string) $totals->leave_pay);
            $otLine       = Money::add((string) $totals->overtime, (string) $totals->night_diff, (string) $totals->holiday);
            $sssEr        = (string) $totals->sss_er;
            $phEr         = (string) $totals->ph_er;
            $pgEr         = (string) $totals->pg_er;
            $sssTotal     = Money::add((string) $totals->sss_ee,        $sssEr);
            $phTotal      = Money::add((string) $totals->ph_ee,         $phEr);
            $pgTotal      = Money::add((string) $totals->pg_ee,         $pgEr);
            $wht          = (string) $totals->wht;
            $loans        = (string) $totals->loans;
            $net          = (string) $totals->net;
            // Lateness withheld from pay. Earnings are debited at their full
            // gross, so this must come back as a credit or the entry cannot
            // balance — this omission is why payroll GL posting had never
            // succeeded on any period that had a single late employee.
            $lateness     = Money::add((string) $totals->tardiness, (string) $totals->undertime);
            // Signed net effect of applied adjustments (positive = extra pay).
            $adjustments  = (string) $totals->adjustments;
            $grossTotal   = (string) $totals->gross;
            // Employee-borne deductions — the withholdings that reduce take-home
            // pay. Used to derive the net-pay-floor remainder below.
            $eeDeductions = Money::add(
                (string) $totals->sss_ee,
                (string) $totals->ph_ee,
                (string) $totals->pg_ee,
                $wht,
                $loans,
            );

            if ($isThirteenth) {
                // 13th-month: gross is in basic_pay slot in the calc-and-pay flow,
                // but for accounting we expense it under 13th Month and credit a
                // dedicated payable (paid out via separate disbursement).
                $debit($code('thirteenth_expense'),  $net, '13th Month Expense');
                $credit($code('thirteenth_payable'), $net, '13th Month Pay Payable');
            } else {
                // Salary expense
                $debit($code('salary_expense'),  $basicLine, 'Salaries Expense');
                $debit($code('overtime_expense'),  $otLine,    'Overtime + Night Diff + Holiday Premium Expense');

                // Tardiness / undertime withheld — contra to Salaries Expense.
                $credit($code('salary_expense'), $lateness, 'Tardiness + Undertime Withheld');

                // Applied adjustments (back-pay, corrections). Signed: a
                // positive figure is extra pay owed (more expense), a negative
                // one recovers an overpayment.
                if (Money::lt($adjustments, '0')) {
                    $credit($code('salary_expense'), Money::negate($adjustments), 'Payroll Adjustments (recovery)');
                } else {
                    $debit($code('salary_expense'), $adjustments, 'Payroll Adjustments (back-pay)');
                }

                // Employer expenses
                $debit($code('sss_employer_expense'),  $sssEr, 'SSS Employer Share Expense');
                $debit($code('philhealth_employer_expense'),  $phEr,  'PhilHealth Employer Share Expense');
                $debit($code('pagibig_employer_expense'),  $pgEr,  'Pag-IBIG Employer Share Expense');

                // Liability credits (gov)
                $credit($code('sss_payable'), $sssTotal, 'SSS Payable (EE+ER)');
                $credit($code('philhealth_payable'), $phTotal,  'PhilHealth Payable (EE+ER)');
                $credit($code('pagibig_payable'), $pgTotal,  'Pag-IBIG Payable (EE+ER)');
                $credit($code('withholding_payable'), $wht,      'Withholding Tax Payable');

                // Loan recovery returns to Loans Payable
                    $credit($code('loans_payable'), $loans,    'Employee Loans Payable');

                // Cash outflow for net pay
                $credit($code('cash'), $net, 'Cash in Bank — Net Pay Disbursed');

                // Net pay is clamped at zero when deductions would exceed pay,
                // so the un-recovered remainder is not cash that ever left the
                // bank. Credit it back explicitly rather than letting it show
                // up as a mystery imbalance. Derived, not plugged: it is the
                // exact shortfall between what was withheld and what the payroll
                // rows could actually absorb.
                $unrecovered = Money::sub(
                    Money::add(Money::sub($grossTotal, $eeDeductions), $adjustments),
                    $net,
                );
                if (Money::lt($unrecovered, '0')) {
                    $debit($code('salary_expense'), Money::negate($unrecovered), 'Net Pay Floor Adjustment');
                } else {
                    $credit($code('salary_expense'), $unrecovered, 'Unrecovered Deductions (net pay floored at zero)');
                }
            }

            if (Money::cmp($totalDebit, $totalCredit) !== 0) {
                throw new BusinessRuleException(sprintf(
                    'Payroll GL posting unbalanced: debits=%s credits=%s',
                    $totalDebit, $totalCredit,
                ));
            }

            $entryNumber = $this->sequences->generate('journal_entry');
            $entryId = DB::table('journal_entries')->insertGetId([
                'entry_number'   => $entryNumber,
                'date'           => $period->payroll_date,
                'description'    => sprintf('Payroll · %s', $period->label()),
                'reference_type' => 'payroll_period',
                'reference_id'   => $period->id,
                'total_debit'    => $totalDebit,
                'total_credit'   => $totalCredit,
                'status'         => 'posted',
                'posted_at'      => now(),
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            foreach ($lines as $line) {
                DB::table('journal_entry_lines')->insert(array_merge($line, [
                    'journal_entry_id' => $entryId,
                ]));
            }

            $period->journal_entry_id = $entryId;
            $period->save();

            return $entryId;
        });
    }
}
