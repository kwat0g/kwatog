<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            ['quality.spc.default_subgroup_size', 5, 'SPC Default Subgroup Size', 'quality'],
            ['mrp.mold.cost_trend_default_months', 12, 'Mold Cost Trend Default Months', 'mrp'],
        ] as [$key, $value, $label, $group]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value), 'group' => $group,
                'label' => $label, 'description' => 'Default window used when no explicit value is supplied.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['quality.spc.default_subgroup_size', 'mrp.mold.cost_trend_default_months'])->delete();
    }
};
