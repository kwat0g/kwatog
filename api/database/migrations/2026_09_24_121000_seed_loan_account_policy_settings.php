<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $accounts = [
            ['code' => '1110', 'name' => 'Employee Loans Receivable', 'type' => 'asset', 'normal_balance' => 'debit', 'parent_code' => '1000'],
            ['code' => '6130', 'name' => 'Employee Loan Write-off Expense', 'type' => 'expense', 'normal_balance' => 'debit', 'parent_code' => '6000'],
        ];
        $ids = DB::table('accounts')->pluck('id', 'code');

        foreach ($accounts as $account) {
            if (isset($ids[$account['code']]) || ! isset($ids[$account['parent_code']])) {
                continue;
            }

            DB::table('accounts')->insert([
                'code' => $account['code'],
                'name' => $account['name'],
                'type' => $account['type'],
                'normal_balance' => $account['normal_balance'],
                'parent_id' => $ids[$account['parent_code']],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ([
            ['accounting.accounts.loan_disbursement_receivable_code', '1110', 'Employee Loan Disbursement Receivable Account Code', 'Asset account debited when an approved employee loan is disbursed.'],
            ['accounting.accounts.loan_disbursement_cash_code', '1020', 'Employee Loan Disbursement Cash Account Code', 'Cash account credited when an approved employee loan is disbursed.'],
            ['accounting.accounts.loan_disbursement_interest_income_code', '4020', 'Employee Loan Interest Income Account Code', 'Revenue account credited for interest included in an employee loan balance.'],
            ['accounting.accounts.loan_repayment_cash_code', '1020', 'Employee Loan Repayment Cash Account Code', 'Cash account debited for a manual employee loan repayment.'],
            ['accounting.accounts.loan_repayment_receivable_code', '1110', 'Employee Loan Repayment Receivable Account Code', 'Employee loan receivable account credited for a manual repayment.'],
            ['accounting.accounts.loan_write_off_expense_code', '6130', 'Employee Loan Write-off Expense Account Code', 'Expense account debited when Finance approves an employee loan write-off.'],
            ['accounting.accounts.loan_write_off_receivable_code', '1110', 'Employee Loan Write-off Receivable Account Code', 'Employee loan receivable account credited when Finance approves a write-off.'],
        ] as [$key, $value, $label, $description]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($value),
                'group' => 'accounting',
                'label' => $label,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'accounting.accounts.loan_disbursement_receivable_code',
            'accounting.accounts.loan_disbursement_cash_code',
            'accounting.accounts.loan_disbursement_interest_income_code',
            'accounting.accounts.loan_repayment_cash_code',
            'accounting.accounts.loan_repayment_receivable_code',
            'accounting.accounts.loan_write_off_expense_code',
            'accounting.accounts.loan_write_off_receivable_code',
        ])->delete();

        foreach (['1110', '6130'] as $code) {
            $id = DB::table('accounts')->where('code', $code)->value('id');
            if ($id && ! DB::table('journal_entry_lines')->where('account_id', $id)->exists()) {
                DB::table('accounts')->where('id', $id)->delete();
            }
        }
    }
};
