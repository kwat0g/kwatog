<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'inventory.low_stock.notification_roles',
            'value' => json_encode(['purchasing_officer', 'warehouse_staff']),
            'group' => 'inventory',
            'label' => 'Low Stock Notification Roles',
            'description' => 'Active role slugs that receive notifications when an automatic low-stock purchase request is created.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'inventory.low_stock.notification_roles')->delete();
    }
};
