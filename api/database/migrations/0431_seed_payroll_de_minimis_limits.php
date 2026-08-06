<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const LIMITS = [
        'rice_subsidy' => ['monthly_limit' => 2000.00],
        'uniform_allowance' => ['monthly_limit' => 500.00, 'annual_limit' => 6000.00],
        'medical_cash_allowance' => ['monthly_limit' => 1500.00],
        'laundry_allowance' => ['monthly_limit' => 300.00],
        'employee_achievement_award' => ['monthly_limit' => 833.33, 'annual_limit' => 10000.00],
        'gifts' => ['monthly_limit' => 416.67, 'annual_limit' => 5000.00],
        'meal_allowance_per_ot' => ['monthly_limit' => 0.00],
    ];

    public function up(): void
    {
        foreach (self::LIMITS as $type => $limits) {
            foreach ($limits as $name => $value) {
                DB::table('settings')->insertOrIgnore([
                    'key' => "payroll.de_minimis.{$type}.{$name}",
                    'value' => json_encode($value),
                    'group' => 'payroll',
                    'label' => ucwords(str_replace('_', ' ', "De minimis {$type} {$name}")),
                    'description' => 'Configurable statutory de minimis benefit limit.',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::LIMITS as $type => $limits) {
            DB::table('settings')->whereIn('key', array_map(
                static fn (string $name): string => "payroll.de_minimis.{$type}.{$name}",
                array_keys($limits),
            ))->delete();
        }
    }
};
