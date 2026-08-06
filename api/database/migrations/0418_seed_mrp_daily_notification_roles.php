<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'mrp.daily_run.notification_roles',
            'value' => json_encode(['ppc_head']),
            'group' => 'mrp',
            'label' => 'Daily MRP Notification Roles',
            'description' => 'Role slugs that receive completion notifications after the scheduled daily MRP run.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'mrp.daily_run.notification_roles')->delete();
    }
};
