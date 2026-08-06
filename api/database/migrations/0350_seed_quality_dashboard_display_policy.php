<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROWS = [
        ['quality.dashboard.defect_danger_pct', 75, 'Quality Defect Danger Display Threshold'],
        ['quality.dashboard.defect_warning_pct', 50, 'Quality Defect Warning Display Threshold'],
        ['quality.dashboard.coverage_success_pct', 90, 'Quality Coverage Success Display Threshold'],
        ['quality.dashboard.coverage_info_pct', 75, 'Quality Coverage Info Display Threshold'],
        ['quality.dashboard.coverage_warning_pct', 50, 'Quality Coverage Warning Display Threshold'],
    ];

    public function up(): void
    {
        foreach (self::ROWS as [$key, $value, $label]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value), 'group' => 'quality',
                'label' => $label,
                'description' => 'Quality dashboard visual status threshold.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_column(self::ROWS, 0))->delete();
    }
};
