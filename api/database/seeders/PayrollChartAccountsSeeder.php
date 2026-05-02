<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Front-loads the minimum COA needed for payroll GL posting (Task 29).
 *
 * Sprint 4 / Task 31 will install the FULL COA with parent groups, etc. This
 * seeder uses upsert semantics so re-running it is harmless once Sprint 4
 * lands its richer seeder.
 */
class PayrollChartAccountsSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('accounts')) {
            $this->command?->warn('PayrollChartAccountsSeeder: accounts table not present — skipping.');
            return;
        }

        $accounts = [
            // Assets
            ['code' => '1010', 'name' => 'Cash in Bank',                  'type' => 'asset',     'normal_balance' => 'debit'],
            // Liabilities
            ['code' => '2020', 'name' => 'SSS Payable',                   'type' => 'liability', 'normal_balance' => 'credit'],
            ['code' => '2030', 'name' => 'PhilHealth Payable',            'type' => 'liability', 'normal_balance' => 'credit'],
            ['code' => '2040', 'name' => 'Pag-IBIG Payable',              'type' => 'liability', 'normal_balance' => 'credit'],
            ['code' => '2050', 'name' => 'Withholding Tax Payable',       'type' => 'liability', 'normal_balance' => 'credit'],
            ['code' => '2080', 'name' => '13th Month Pay Payable',        'type' => 'liability', 'normal_balance' => 'credit'],
            ['code' => '2100', 'name' => 'Loans Payable',                 'type' => 'liability', 'normal_balance' => 'credit'],
            // Expenses
            ['code' => '5050', 'name' => 'Salaries Expense',              'type' => 'expense',   'normal_balance' => 'debit'],
            ['code' => '5060', 'name' => 'Overtime Expense',              'type' => 'expense',   'normal_balance' => 'debit'],
            ['code' => '6030', 'name' => 'SSS Expense (Employer)',        'type' => 'expense',   'normal_balance' => 'debit'],
            ['code' => '6040', 'name' => 'PhilHealth Expense (Employer)', 'type' => 'expense',   'normal_balance' => 'debit'],
            ['code' => '6050', 'name' => 'Pag-IBIG Expense (Employer)',   'type' => 'expense',   'normal_balance' => 'debit'],
            ['code' => '5070', 'name' => '13th Month Expense',            'type' => 'expense',   'normal_balance' => 'debit'],
        ];

        foreach ($accounts as $a) {
            DB::table('accounts')->updateOrInsert(
                ['code' => $a['code']],
                array_merge($a, [
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
            );
        }

        $this->command?->info('Payroll-related Chart of Accounts seeded ('.count($accounts).' accounts).');
    }
}
