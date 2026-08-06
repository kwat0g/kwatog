<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            ['inventory.stockout.default_horizon_days', 60, 'Default Stock-out Horizon Days'],
            ['inventory.stockout.minimum_horizon_days', 7, 'Minimum Stock-out Horizon Days'],
            ['inventory.stockout.maximum_horizon_days', 180, 'Maximum Stock-out Horizon Days'],
        ] as [$key, $value, $label]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value), 'group' => 'inventory',
                'label' => $label, 'description' => 'Controls the stock-out projection request window.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'inventory.stockout.default_horizon_days', 'inventory.stockout.minimum_horizon_days', 'inventory.stockout.maximum_horizon_days',
        ])->delete();
    }
};
