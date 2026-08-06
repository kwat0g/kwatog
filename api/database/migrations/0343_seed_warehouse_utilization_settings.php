<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROWS = [
        ['inventory.dashboard.zone_utilization_warning_ratio', 0.75, 'Warehouse Zone Utilization Warning Ratio'],
        ['inventory.dashboard.zone_utilization_critical_ratio', 0.90, 'Warehouse Zone Utilization Critical Ratio'],
    ];

    public function up(): void
    {
        foreach (self::ROWS as [$key, $value, $label]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value), 'group' => 'inventory',
                'label' => $label, 'description' => 'Zone utilization color threshold for warehouse dashboards.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_column(self::ROWS, 0))->delete();
    }
};
