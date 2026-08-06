<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'security.auth_history_window_hours',
            'value' => json_encode(24),
            'group' => 'security',
            'label' => 'Authentication History Window',
            'description' => 'Hours of login activity shown in the administrator security dashboard.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'security.auth_history_window_hours')->delete();
    }
};
