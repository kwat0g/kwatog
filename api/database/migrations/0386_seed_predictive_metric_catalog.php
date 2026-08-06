<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'maintenance.predictive.metrics',
            'value' => json_encode([
                ['value' => 'temperature', 'label' => 'Temperature', 'unit' => 'celsius'],
                ['value' => 'vibration', 'label' => 'Vibration', 'unit' => 'mm/s'],
                ['value' => 'pressure', 'label' => 'Pressure', 'unit' => 'bar'],
                ['value' => 'current', 'label' => 'Current', 'unit' => 'amp'],
                ['value' => 'oil_quality', 'label' => 'Oil Quality', 'unit' => 'percent'],
            ]),
            'group' => 'maintenance',
            'label' => 'Predictive Maintenance Metric Catalog',
            'description' => 'Machine condition metrics and units available for predictive-maintenance readings.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'maintenance.predictive.metrics')->delete();
    }
};
