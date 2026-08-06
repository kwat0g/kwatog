<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            ['dashboard.action_center.source_limit', 30, 'Action Center Source Limit', 'Maximum records loaded from each Action Center source.'],
            ['dashboard.action_center.max_items', 100, 'Action Center Maximum Items', 'Maximum combined Action Center items returned to the dashboard.'],
        ] as [$key, $value, $label, $description]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($value),
                'group' => 'dashboard',
                'label' => $label,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'dashboard.action_center.source_limit',
            'dashboard.action_center.max_items',
        ])->delete();
    }
};
