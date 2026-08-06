<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'hr.default_user_role_slug',
            'value' => json_encode('employee'),
            'group' => 'hr',
            'label' => 'Default Employee Account Role',
            'description' => 'Role assigned when an employee account is provisioned automatically.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'hr.default_user_role_slug')->delete();
    }
};
