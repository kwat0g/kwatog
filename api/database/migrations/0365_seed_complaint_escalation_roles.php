<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'crm.complaint_8d.notification_roles',
            'value' => json_encode(['quality', 'qc_inspector']),
            'group' => 'crm', 'label' => '8D Escalation Notification Roles',
            'description' => 'Role slugs notified when a customer complaint 8D milestone is overdue.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'crm.complaint_8d.notification_roles')->delete();
    }
};
