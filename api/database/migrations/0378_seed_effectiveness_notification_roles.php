<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'quality.effectiveness.overdue_notification_roles',
            'value' => json_encode(['production_manager']),
            'group' => 'quality',
            'label' => 'Effectiveness Overdue Notification Roles',
            'description' => 'Roles notified when CAPA effectiveness checks become overdue.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'quality.effectiveness.overdue_notification_roles')->delete();
    }
};
