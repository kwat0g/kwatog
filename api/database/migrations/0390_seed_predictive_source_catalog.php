<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            ['maintenance.predictive.sources', [
                ['value' => 'manual', 'label' => 'Manual'],
                ['value' => 'iot_sensor', 'label' => 'IoT sensor'],
                ['value' => 'plc', 'label' => 'PLC'],
                ['value' => 'api', 'label' => 'API'],
            ], 'Predictive Reading Sources'],
            ['maintenance.predictive.default_source', 'manual', 'Predictive Reading Default Source'],
        ] as [$key, $value, $label]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value), 'group' => 'maintenance',
                'label' => $label, 'description' => 'Predictive-maintenance condition-reading source policy.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['maintenance.predictive.sources', 'maintenance.predictive.default_source'])->delete();
    }
};
