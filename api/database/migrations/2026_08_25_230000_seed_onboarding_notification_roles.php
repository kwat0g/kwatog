<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'hr.onboarding.notification_roles',
            'value' => json_encode(['hr_officer', 'system_admin']),
            'group' => 'hr',
            'label' => 'Onboarding Reminder Notification Roles',
            'description' => 'Active role slugs that receive reminders for stale employee onboarding records.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'hr.onboarding.notification_roles')->delete();
    }
};
