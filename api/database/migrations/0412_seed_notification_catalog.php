<?php

declare(strict_types=1);

use App\Common\Services\NotificationCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'notifications.catalog',
            'value' => json_encode(NotificationCatalog::defaults()),
            'group' => 'notifications',
            'label' => 'Notification Preference Catalog',
            'description' => 'Groups and notification types shown in user notification preferences.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'notifications.catalog')->delete();
    }
};
