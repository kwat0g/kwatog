<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'calendar.max_range_days', 'value' => json_encode(90),
            'group' => 'dashboard', 'label' => 'Calendar Maximum Range (Days)',
            'description' => 'Maximum date span returned by the cross-module calendar endpoint.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'calendar.max_range_days')->delete();
    }
};
