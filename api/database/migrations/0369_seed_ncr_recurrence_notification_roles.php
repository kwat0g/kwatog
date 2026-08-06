<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'quality.ncr.recurrence_notification_roles',
            'value' => json_encode(['qc_inspector', 'production_manager']),
            'group' => 'quality',
            'label' => 'NCR Recurrence Notification Roles',
            'description' => 'Active role slugs that receive notifications when a recurring NCR is detected.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'quality.ncr.recurrence_notification_roles')->delete();
    }
};
