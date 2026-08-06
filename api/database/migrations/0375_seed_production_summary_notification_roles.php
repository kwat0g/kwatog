<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'production.summary.notification_roles',
            'value' => json_encode(['production_manager', 'system_admin']),
            'group' => 'production',
            'label' => 'Production Summary Notification Roles',
            'description' => 'Active role slugs that receive daily and weekly production summary emails.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'production.summary.notification_roles')->delete();
    }
};
