<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NEW_ROWS = [
        ['accounting.accounts.production_salary_expense_code', '5020', 'Production Salary Expense Account Code'],
        ['accounting.accounts.production_overtime_expense_code', '5020', 'Production Overtime Expense Account Code'],
        ['accounting.accounts.production_thirteenth_month_expense_code', '5070', 'Production 13th Month Expense Account Code'],
        ['accounting.payroll.direct_labor_department_codes', ['PROD', 'PRD'], 'Direct Labor Department Codes'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach (self::NEW_ROWS as [$key, $value, $label]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($value),
                'group' => 'accounting',
                'label' => $label,
                'description' => 'Payroll expense classification policy.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $replacements = [
            'accounting.accounts.salary_expense_code' => ['5050', '6010'],
            'accounting.accounts.overtime_expense_code' => ['5060', '6015'],
            'accounting.accounts.thirteenth_month_expense_code' => ['5070', '6020'],
        ];
        foreach ($replacements as $key => [$old, $new]) {
            DB::table('settings')
                ->where('key', $key)
                ->whereRaw('value::text = ?', [json_encode($old)])
                ->update(['value' => json_encode($new), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')->whereIn('key', array_column(self::NEW_ROWS, 0))->delete();

        foreach ([
            'accounting.accounts.salary_expense_code' => ['6010', '5050'],
            'accounting.accounts.overtime_expense_code' => ['6015', '5060'],
            'accounting.accounts.thirteenth_month_expense_code' => ['6020', '5070'],
        ] as $key => [$new, $old]) {
            DB::table('settings')
                ->where('key', $key)
                ->whereRaw('value::text = ?', [json_encode($new)])
                ->update(['value' => json_encode($old), 'updated_at' => now()]);
        }
    }
};
