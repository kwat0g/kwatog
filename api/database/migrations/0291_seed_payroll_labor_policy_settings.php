<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROWS = [
        ['payroll.work_days_per_month', 22, 'payroll', 'Payroll Work Days per Month', 'Divisor used to derive daily rates from monthly salary.'],
        ['payroll.hours_per_day', 8, 'payroll', 'Payroll Hours per Day', 'Divisor used to derive hourly rates and day equivalents.'],
        ['payroll.overtime.ordinary_multiplier', 1.25, 'payroll', 'Ordinary-day Overtime Multiplier', 'Multiplier applied to ordinary-day overtime hours.'],
        ['payroll.overtime.premium_day_multiplier', 1.30, 'payroll', 'Premium-day Overtime Multiplier', 'Multiplier applied to overtime on rest days and holidays.'],
        ['payroll.night_differential_rate', 0.10, 'payroll', 'Night Differential Rate', 'Additive hourly premium for eligible night work.'],
        ['payroll.pagibig.compensation_ceiling', 10000, 'payroll', 'Pag-IBIG Compensation Ceiling', 'Maximum monthly compensation basis used for Pag-IBIG contributions.'],
    ];

    public function up(): void
    {
        foreach (self::ROWS as [$key, $value, $group, $label, $description]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value), 'group' => $group,
                'label' => $label, 'description' => $description,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_column(self::ROWS, 0))->delete();
    }
};
