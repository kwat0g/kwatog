<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4 / Task 31 — full Chart of Accounts (~45 accounts) per
 * docs/SEEDS.md section 6, plus a few legacy codes used by the Sprint 3
 * payroll GL posting service so existing tests stay green.
 *
 * Idempotent (upsert on `code`). Two-pass insert so parents exist before
 * children reference them via parent_id.
 *
 * IMPORTANT: this seeder uses the column name `type` to match the existing
 * 0038_create_accounts_table.php migration. The plan originally proposed
 * renaming to `account_type` (per docs/SCHEMA.md), but the running codebase
 * already uses `type`. We honour the running schema.
 */
class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('accounts')) {
            $this->command?->warn('ChartOfAccountsSeeder: accounts table missing — run migrations first.');
            return;
        }

        // [code, name, type, normal, parent_code|null]
        $rows = [
            // ─── Top-level groups ──────────────────────────
            ['1000', 'Assets',                       'asset',     'debit',  null],
            ['2000', 'Liabilities',                  'liability', 'credit', null],
            ['3000', 'Equity',                       'equity',    'credit', null],
            ['4000', 'Revenue',                      'revenue',   'credit', null],
            ['5000', 'Cost of Goods Sold',           'expense',   'debit',  null],
            ['6000', 'Operating Expenses',           'expense',   'debit',  null],

            // ─── Assets ────────────────────────────────────
            ['1010', 'Cash on Hand',                 'asset', 'debit', '1000'],
            ['1020', 'Cash in Bank',                 'asset', 'debit', '1000'],
            ['1030', 'Petty Cash',                   'asset', 'debit', '1000'],
            ['1100', 'Accounts Receivable',          'asset', 'debit', '1000'],
            ['1200', 'Inventory - Raw Materials',    'asset', 'debit', '1000'],
            ['1210', 'Inventory - Finished Goods',   'asset', 'debit', '1000'],
            ['1220', 'Inventory - Packaging',        'asset', 'debit', '1000'],
            ['1230', 'Inventory - Spare Parts',      'asset', 'debit', '1000'],
            ['1300', 'Prepaid Expenses',             'asset', 'debit', '1000'],
            ['1310', 'VAT Input',                    'asset', 'debit', '1000'],
            ['1400', 'Property Plant & Equipment',   'asset', 'debit', '1000'],
            ['1410', 'Accumulated Depreciation',     'asset', 'credit','1000'],

            // ─── Liabilities ───────────────────────────────
            ['2010', 'Accounts Payable',             'liability', 'credit', '2000'],
            ['2020', 'SSS Payable',                  'liability', 'credit', '2000'],
            ['2030', 'PhilHealth Payable',           'liability', 'credit', '2000'],
            ['2040', 'Pag-IBIG Payable',             'liability', 'credit', '2000'],
            ['2050', 'Withholding Tax Payable',      'liability', 'credit', '2000'],
            ['2060', 'VAT Output',                   'liability', 'credit', '2000'],
            ['2070', 'Accrued Expenses',             'liability', 'credit', '2000'],
            ['2080', '13th Month Pay Payable',       'liability', 'credit', '2000'],
            ['2100', 'Loans Payable',                'liability', 'credit', '2000'],

            // ─── Equity ────────────────────────────────────
            ['3010', 'Capital Stock',                'equity', 'credit', '3000'],
            ['3020', 'Retained Earnings',            'equity', 'credit', '3000'],

            // ─── Revenue ───────────────────────────────────
            ['4010', 'Sales Revenue',                'revenue', 'credit', '4000'],
            ['4020', 'Other Income',                 'revenue', 'credit', '4000'],

            // ─── COGS ──────────────────────────────────────
            ['5010', 'Direct Materials',             'expense', 'debit', '5000'],
            ['5020', 'Direct Labor',                 'expense', 'debit', '5000'],
            ['5030', 'Manufacturing Overhead',       'expense', 'debit', '5000'],
            // Legacy payroll codes — preserved so PayrollGlPostingService
            // (Sprint 3) keeps working. Slated for reconciliation in Sprint 8.
            ['5050', 'Salaries Expense (legacy)',         'expense', 'debit', '5000'],
            ['5060', 'Overtime Expense (legacy)',         'expense', 'debit', '5000'],
            ['5070', '13th Month Expense',                'expense', 'debit', '5000'],

            // ─── Operating Expenses ────────────────────────
            ['6010', 'Salaries & Wages Expense',     'expense', 'debit', '6000'],
            ['6015', 'Overtime Expense',             'expense', 'debit', '6000'],
            ['6020', 'Employee Benefits Expense',    'expense', 'debit', '6000'],
            ['6030', 'SSS Expense (Employer)',       'expense', 'debit', '6000'],
            ['6040', 'PhilHealth Expense (Employer)','expense', 'debit', '6000'],
            ['6050', 'Pag-IBIG Expense (Employer)',  'expense', 'debit', '6000'],
            ['6060', 'Utilities Expense',            'expense', 'debit', '6000'],
            ['6070', 'Rent Expense',                 'expense', 'debit', '6000'],
            ['6080', 'Depreciation Expense',         'expense', 'debit', '6000'],
            ['6090', 'Office Supplies Expense',      'expense', 'debit', '6000'],
            ['6100', 'Repairs & Maintenance Expense','expense', 'debit', '6000'],
            ['6110', 'Transportation Expense',       'expense', 'debit', '6000'],
        ];

        // Pass 1: insert/upsert without parent_id.
        foreach ($rows as [$code, $name, $type, $normal, $_parent]) {
            DB::table('accounts')->updateOrInsert(
                ['code' => $code],
                [
                    'name'           => $name,
                    'type'           => $type,
                    'normal_balance' => $normal,
                    'is_active'      => true,
                    'updated_at'     => now(),
                    'created_at'     => now(),
                ],
            );
        }

        // Pass 2: resolve parent_id by code.
        $idsByCode = DB::table('accounts')->pluck('id', 'code');
        foreach ($rows as [$code, , , , $parentCode]) {
            if ($parentCode === null) continue;
            DB::table('accounts')
                ->where('code', $code)
                ->update([
                    'parent_id'  => $idsByCode[$parentCode] ?? null,
                    'updated_at' => now(),
                ]);
        }

        $this->command?->info('Chart of Accounts seeded ('.count($rows).' rows).');
    }
}
