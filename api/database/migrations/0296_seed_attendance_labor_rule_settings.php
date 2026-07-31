<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROWS = [
        ['attendance.ot.minimum_minutes', 30, 'attendance', 'Minimum Approved OT Minutes'],
        ['attendance.ot.maximum_minutes', 240, 'attendance', 'Maximum Approved OT Minutes per Day'],
        ['attendance.tardiness.maximum_minutes', 480, 'attendance', 'Maximum Daily Tardiness Minutes'],
        ['attendance.night_band_start_hour', 22, 'attendance', 'Night Differential Start Hour'],
        ['attendance.night_band_end_hour', 6, 'attendance', 'Night Differential End Hour'],
        ['attendance.half_day_work_ratio', 0.50, 'attendance', 'Half-day Work Ratio'],
        ['payroll.day_rate.regular_holiday', 2.00, 'payroll', 'Regular Holiday Work Multiplier'],
        ['payroll.day_rate.regular_holiday_rest_day', 2.60, 'payroll', 'Regular Holiday Rest-day Multiplier'],
        ['payroll.day_rate.special_holiday', 1.30, 'payroll', 'Special Holiday Work Multiplier'],
        ['payroll.day_rate.special_holiday_rest_day', 1.50, 'payroll', 'Special Holiday Rest-day Multiplier'],
        ['payroll.day_rate.rest_day', 1.30, 'payroll', 'Rest-day Work Multiplier'],
    ];

    public function up(): void
    {
        foreach (self::ROWS as [$key, $value, $group, $label]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value), 'group' => $group,
                'label' => $label, 'description' => 'Configurable attendance and payroll labor-rule value.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_column(self::ROWS, 0))->delete();
    }
};
