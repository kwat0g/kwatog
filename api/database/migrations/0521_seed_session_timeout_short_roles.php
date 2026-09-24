<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'security.session_timeout_short_roles',
            'value' => json_encode(['employee'], JSON_THROW_ON_ERROR),
            'group' => 'security',
            'label' => 'Short Session Timeout Roles',
            'description' => 'Role slugs that use the employee idle-session timeout.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'security.session_timeout_short_roles')->delete();
    }
};
