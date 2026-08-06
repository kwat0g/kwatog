<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'forecasting.methods',
            'value' => json_encode([
                ['value' => 'moving_avg', 'label' => 'Simple moving average'],
                ['value' => 'weighted_avg', 'label' => 'Weighted (recency-biased)'],
                ['value' => 'manual', 'label' => 'Manual'],
            ]),
            'group' => 'forecasting',
            'label' => 'Forecasting Method Catalog',
            'description' => 'Forecasting methods and labels exposed to planners and forecast APIs.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'forecasting.methods')->delete();
    }
};
