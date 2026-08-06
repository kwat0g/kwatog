<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const ROWS = [
        ['forecasting.trend_slope_ratio', 0.03, 'forecasting', 'Forecast Trend Slope Ratio', 'Relative slope required to classify a forecast series as trending up or down.'],
        ['forecasting.trend_minimum_slope', 0.5, 'forecasting', 'Forecast Trend Minimum Slope', 'Absolute slope floor used when the series mean is zero or near zero.'],
    ];

    public function up(): void
    {
        foreach (self::ROWS as [$key, $value, $group, $label, $description]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($value),
                'group' => $group,
                'label' => $label,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_column(self::ROWS, 0))->delete();
    }
};
