<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DSS W0-5 (F-02) — one tunable settings key per demand consumer, seeded to
 * the exact legacy sets so behavior is byte-identical on day one.
 *
 * Numbered name: touches only the `settings` table (created by 0013) and
 * depends on nothing any `2026_*` migration introduces.
 */
return new class extends Migration
{
    private const SETS = [
        'inventory.demand.replenishment_movement_types' => ['material_issue', 'delivery', 'scrap'],
        'inventory.demand.safety_stock_movement_types' => ['material_issue', 'delivery', 'adjustment_out', 'scrap', 'return_to_vendor'],
        'inventory.demand.projection_movement_types' => ['material_issue', 'consume', 'production_issue'],
    ];

    public function up(): void
    {
        foreach (self::SETS as $key => $types) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($types),
                'group' => 'inventory',
                'label' => "Demand movement types ({$key})",
                'description' => 'F-02: the DemandBasis default for this consumer. Edit with care — it changes replenishment, safety-stock, or projection outputs.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_keys(self::SETS))->delete();
    }
};
