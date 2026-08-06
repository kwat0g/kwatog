<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'attendance.ot.admin_max_hours', 'value' => json_encode(8.0), 'group' => 'attendance',
            'label' => 'Admin OT Maximum Hours', 'description' => 'Maximum overtime hours an administrator may file for an employee per day.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'attendance.ot.admin_max_hours')->delete();
    }
};
