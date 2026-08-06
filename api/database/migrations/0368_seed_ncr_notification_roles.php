<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'quality.ncr.auto_created_notification_roles',
            'value' => json_encode(['qc_inspector', 'system_admin']),
            'group' => 'quality',
            'label' => 'Auto-created NCR Notification Roles',
            'description' => 'Active role slugs that receive notifications when an inspection automatically creates an NCR.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'quality.ncr.auto_created_notification_roles')->delete();
    }
};
