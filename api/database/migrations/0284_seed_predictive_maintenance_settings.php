<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['key' => 'maintenance.predictive.temperature.max', 'value' => 85.0, 'label' => 'Temperature Maximum', 'description' => 'Maximum safe machine temperature in °C.'],
            ['key' => 'maintenance.predictive.vibration.max', 'value' => 7.1, 'label' => 'Vibration Maximum', 'description' => 'Maximum safe vibration velocity in mm/s.'],
            ['key' => 'maintenance.predictive.pressure.min', 'value' => 2.0, 'label' => 'Pressure Minimum', 'description' => 'Minimum safe hydraulic pressure in bar.'],
            ['key' => 'maintenance.predictive.pressure.max', 'value' => 12.0, 'label' => 'Pressure Maximum', 'description' => 'Maximum safe hydraulic pressure in bar.'],
            ['key' => 'maintenance.predictive.current.max', 'value' => 150.0, 'label' => 'Current Maximum', 'description' => 'Maximum safe current reading in amperes.'],
            ['key' => 'maintenance.predictive.oil_quality.min', 'value' => 70.0, 'label' => 'Oil Quality Minimum', 'description' => 'Minimum acceptable oil quality percentage.'],
            ['key' => 'maintenance.predictive.breach_window', 'value' => 3, 'label' => 'Consecutive Breaches', 'description' => 'Consecutive unsafe readings required before a corrective work order is generated.'],
        ];

        foreach ($rows as $row) {
            DB::table('settings')->insertOrIgnore([
                ...$row,
                'value' => json_encode($row['value']),
                'group' => 'maintenance',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'maintenance.predictive.temperature.max',
            'maintenance.predictive.vibration.max',
            'maintenance.predictive.pressure.min',
            'maintenance.predictive.pressure.max',
            'maintenance.predictive.current.max',
            'maintenance.predictive.oil_quality.min',
            'maintenance.predictive.breach_window',
        ])->delete();
    }
};
