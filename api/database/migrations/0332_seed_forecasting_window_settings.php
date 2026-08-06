<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            ['forecasting.default_history_months', 12, 'Forecasting History Window Months'],
            ['forecasting.default_horizon_months', 3, 'Forecasting Default Horizon Months'],
            ['forecasting.default_lookback_months', 6, 'Forecasting Default Lookback Months'],
        ] as [$key, $value, $label]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($value),
                'group' => 'forecasting',
                'label' => $label,
                'description' => 'Controls the default demand forecasting windows used by the API.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'forecasting.default_history_months',
            'forecasting.default_horizon_months',
            'forecasting.default_lookback_months',
        ])->delete();
    }
};
