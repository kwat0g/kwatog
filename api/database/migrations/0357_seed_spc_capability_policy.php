<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const ROWS = [
        ['quality.spc.cpk_launch_threshold', 1.67, 'SPC Cpk Launch Threshold'],
        ['quality.spc.cpk_ongoing_threshold', 1.33, 'SPC Cpk Ongoing Threshold'],
        ['quality.spc.cpk_action_threshold', 1.0, 'SPC Cpk Action Threshold'],
        ['quality.spc.minimum_capability_samples', 5, 'SPC Minimum Capability Samples'],
    ];

    public function up(): void
    {
        foreach (self::ROWS as [$key, $value, $label]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value), 'group' => 'quality',
                'label' => $label,
                'description' => 'Runtime SPC capability policy.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_column(self::ROWS, 0))->delete();
    }
};
