<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            ['forecasting.minimum_horizon_months', 1, 'Forecast Minimum Horizon Months'],
            ['forecasting.maximum_horizon_months', 12, 'Forecast Maximum Horizon Months'],
            ['forecasting.minimum_lookback_months', 3, 'Forecast Minimum Lookback Months'],
            ['forecasting.maximum_lookback_months', 24, 'Forecast Maximum Lookback Months'],
        ] as [$key, $value, $label]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value), 'group' => 'forecasting',
                'label' => $label, 'description' => 'Bounds for demand forecast windows.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'forecasting.minimum_horizon_months', 'forecasting.maximum_horizon_months',
            'forecasting.minimum_lookback_months', 'forecasting.maximum_lookback_months',
        ])->delete();
    }
};
