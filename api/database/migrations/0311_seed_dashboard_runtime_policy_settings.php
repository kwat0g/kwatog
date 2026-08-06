<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $rows = [
            ['key' => 'quality.rollout.eligible_item_types', 'value' => ['raw_material'], 'group' => 'quality', 'label' => 'Quality Rollout Item Types', 'description' => 'Item types included in quality-plan rollout health coverage.'],
            ['key' => 'quality.rollout.pending_grn_grace_minutes', 'value' => 15, 'group' => 'quality', 'label' => 'Pending GRN Quality Grace', 'description' => 'Minutes a GRN may remain pending QC before rollout health flags it.'],
            ['key' => 'dashboard.badges.cache_ttl_seconds', 'value' => 30, 'group' => 'dashboard', 'label' => 'Badge Cache TTL', 'description' => 'Cache lifetime for sidebar action badges.'],
            ['key' => 'dashboard.badges.danger_threshold', 'value' => 20, 'group' => 'dashboard', 'label' => 'Badge Danger Threshold', 'description' => 'Count above which a sidebar badge is marked danger.'],
            ['key' => 'dashboard.badges.warning_threshold', 'value' => 0, 'group' => 'dashboard', 'label' => 'Badge Warning Threshold', 'description' => 'Count above which a sidebar badge is marked warning.'],
        ];
        foreach ($rows as $row) {
            DB::table('settings')->insertOrIgnore([...$row, 'value' => json_encode($row['value']), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'quality.rollout.eligible_item_types', 'quality.rollout.pending_grn_grace_minutes',
            'dashboard.badges.cache_ttl_seconds', 'dashboard.badges.danger_threshold', 'dashboard.badges.warning_threshold',
        ])->delete();
    }
};
