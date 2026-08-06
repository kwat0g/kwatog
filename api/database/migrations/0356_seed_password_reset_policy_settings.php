<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'security.password_reset_expiry_minutes',
            'value' => json_encode(60),
            'group' => 'security',
            'label' => 'Password Reset Link Expiry (Minutes)',
            'description' => 'Time before a self-service password reset link expires.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'security.password_reset_expiry_minutes')->delete();
    }
};
