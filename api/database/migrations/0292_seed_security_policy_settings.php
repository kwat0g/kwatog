<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROWS = [
        ['security.max_login_attempts', 5, 'security', 'Max Login Attempts', 'Account locks after this many consecutive failed login attempts.'],
        ['security.lockout_minutes', 15, 'security', 'Lockout Minutes', 'Duration of account lockout after excessive failed attempts.'],
        ['security.password_history_depth', 3, 'security', 'Password History Depth', 'Number of previous passwords that cannot be reused.'],
        ['security.password_min_length', 8, 'security', 'Minimum Password Length', 'Minimum number of characters required for passwords.'],
        ['security.session_timeout_employee', 15, 'security', 'Employee Session Timeout', 'Idle minutes before employee sessions expire.'],
        ['security.session_timeout_default', 30, 'security', 'Default Session Timeout', 'Idle minutes before non-employee internal sessions expire.'],
        ['security.password_expiry_days', 90, 'security', 'Password Expiry Days', 'Maximum password age in days; zero disables timed expiry.'],
    ];

    public function up(): void
    {
        foreach (self::ROWS as [$key, $value, $group, $label, $description]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value), 'group' => $group,
                'label' => $label, 'description' => $description,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_column(self::ROWS, 0))->delete();
    }
};
