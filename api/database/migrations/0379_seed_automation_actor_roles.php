<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'system.automation.actor_roles',
            'value' => json_encode(['system_admin']),
            'group' => 'system',
            'label' => 'Automation Actor Roles',
            'description' => 'Active role slugs eligible to attribute system-generated records.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'system.automation.actor_roles')->delete();
    }
};
