<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROWS = [
        ['payroll.anomaly.net_change_ratio', 0.30, 'payroll', 'Payroll Net-Change Alert Ratio', 'Flag net pay changes above this ratio versus the comparable prior payroll.'],
        ['payroll.anomaly.overtime_hours', 80.0, 'payroll', 'Payroll Overtime Alert Hours', 'Flag payrolls whose attendance overtime exceeds this many hours in one period.'],
        ['payroll.anomaly.deduction_ratio', 0.50, 'payroll', 'Payroll Deduction Alert Ratio', 'Flag total deductions above this share of gross pay.'],
        ['mrp.safety_buffer_days', 2, 'mrp', 'MRP Safety Buffer Days', 'Extra days subtracted from material order-by dates after supplier lead time.'],
        ['mrp.default_lead_time_days', 14, 'mrp', 'MRP Default Lead Time Days', 'Lead time used only when neither an item nor an approved supplier has one configured.'],
    ];

    public function up(): void
    {
        foreach (self::ROWS as [$key, $value, $group, $label, $description]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($value),
                'group' => $group,
                'label' => $label,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_column(self::ROWS, 0))->delete();
    }
};
