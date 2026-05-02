<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Common\Services\DocumentSequenceService;
use App\Common\Services\SettingsService;
use App\Common\Support\Money;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

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
    ) {}

    /**
     * Posts the period's totals to the GL. Returns the journal_entry id (or null
     * when skipped/disabled).
     */
    public function post(PayrollPeriod $period): ?int
    {
        if ($period->status !== PayrollPeriodStatus::Finalized) {
            throw new RuntimeException('Only finalized periods can be posted to the GL.');
        }
        if ($period->journal_entry_id) {
            // Already posted — idempotent.
            return (int) $period->journal_entry_id;
        }

        // Feature flag check.
        $accountingEnabled = (bool) $this->settings->get('modules.accounting', false);
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

        return DB::transaction(function () use ($period) {
            // Aggregate totals from payroll rows.
            $totals = DB::table('payrolls')
                ->where('payroll_period_id', $period->id)
                ->whereNull('error_message')
                ->selectRaw('
                    COALESCE(SUM(basic_pay),       0) as basic,
                    COALESCE(SUM(overtime_pay),    0) as overtime,
                    COALESCE(SUM(night_diff_pay),  0) as night_diff,
                    COALESCE(SUM(holiday_pay),     0) as holiday,
                    COALESCE(SUM(sss_ee),          0) as sss_ee,
                    COALESCE(SUM(sss_er),          0) as sss_er,
                    COALESCE(SUM(philhealth_ee),   0) as ph_ee,
                    COALESCE(SUM(philhealth_er),   0) as ph_er,
                    COALESCE(SUM(pagibig_ee),      0) as pg_ee,
                    COALESCE(SUM(pagibig_er),      0) as pg_er,
                    COALESCE(SUM(withholding_tax), 0) as wht,
                    COALESCE(SUM(loan_deductions), 0) as loans,
                    COALESCE(SUM(net_pay),         0) as net
                ')
                ->first();

            $isThirteenth = (bool) $period->is_thirteenth_month;

            // Lookup account ids.
            $accounts = DB::table('accounts')->whereIn('code', [
                '1010','2020','2030','2040','2050','2080','2100','5050','5060','5070','6030','6040','6050',
            ])->pluck('id', 'code');

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

            $basicLine    = (string) $totals->basic;
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

            if ($isThirteenth) {
                // 13th-month: gross is in basic_pay slot in the calc-and-pay flow,
                // but for accounting we expense it under 13th Month and credit a
                // dedicated payable (paid out via separate disbursement).
                $debit('5070',  $net, '13th Month Expense');
                $credit('2080', $net, '13th Month Pay Payable');
            } else {
                // Salary expense
                $debit('5050',  $basicLine, 'Salaries Expense');
                $debit('5060',  $otLine,    'Overtime + Night Diff + Holiday Premium Expense');

                // Employer expenses
                $debit('6030',  $sssEr, 'SSS Employer Share Expense');
                $debit('6040',  $phEr,  'PhilHealth Employer Share Expense');
                $debit('6050',  $pgEr,  'Pag-IBIG Employer Share Expense');

                // Liability credits (gov)
                $credit('2020', $sssTotal, 'SSS Payable (EE+ER)');
                $credit('2030', $phTotal,  'PhilHealth Payable (EE+ER)');
                $credit('2040', $pgTotal,  'Pag-IBIG Payable (EE+ER)');
                $credit('2050', $wht,      'Withholding Tax Payable');

                // Loan recovery returns to Loans Payable
                $credit('2100', $loans,    'Employee Loans Payable');

                // Cash outflow for net pay
                $credit('1010', $net, 'Cash in Bank — Net Pay Disbursed');
            }

            if (Money::cmp($totalDebit, $totalCredit) !== 0) {
                throw new RuntimeException(sprintf(
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
