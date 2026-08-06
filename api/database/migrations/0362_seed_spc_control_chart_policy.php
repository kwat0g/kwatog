<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            ['quality.spc.minimum_control_points', 20, 'SPC Minimum Control Points'],
            ['quality.spc.recalculate_after_points', 25, 'SPC Recalculation Start Points'],
            ['quality.spc.recalculate_interval_points', 5, 'SPC Recalculation Interval Points'],
            ['quality.spc.display_history_points', 50, 'SPC Display History Points'],
        ] as [$key, $value, $label]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value), 'group' => 'quality',
                'label' => $label, 'description' => 'Runtime SPC control-chart sampling policy.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'quality.spc.minimum_control_points', 'quality.spc.recalculate_after_points',
            'quality.spc.recalculate_interval_points', 'quality.spc.display_history_points',
        ])->delete();
    }
};
