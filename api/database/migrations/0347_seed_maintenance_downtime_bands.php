<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROWS = [
        ['maintenance.downtime.total_warning_minutes', 480, 'Maintenance Total Downtime Warning Minutes'],
        ['maintenance.downtime.mtbf_good_hours', 48, 'Maintenance MTBF Good Hours'],
        ['maintenance.downtime.mttr_good_minutes', 60, 'Maintenance MTTR Good Minutes'],
        ['maintenance.downtime.breakdown_warning_count', 1, 'Maintenance Breakdown Warning Count'],
        ['maintenance.downtime.breakdown_critical_count', 3, 'Maintenance Breakdown Critical Count'],
    ];

    public function up(): void
    {
        foreach (self::ROWS as [$key, $value, $label]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value), 'group' => 'maintenance',
                'label' => $label,
                'description' => 'Threshold used by maintenance downtime analytics.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_column(self::ROWS, 0))->delete();
    }
};
