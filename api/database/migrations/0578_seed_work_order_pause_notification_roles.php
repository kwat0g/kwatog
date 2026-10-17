<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'production.work_order_paused.notification_roles',
            'value' => json_encode(['ppc_head', 'production_manager']),
            'group' => 'production',
            'label' => 'Paused Work Order Notification Roles',
            'description' => 'Roles notified when a production work order is paused.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'production.work_order_paused.notification_roles')->delete();
    }
};
