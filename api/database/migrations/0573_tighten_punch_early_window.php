<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Early time-in window: 120 -> 15 minutes. Only moves the untouched default. */
    public function up(): void
    {
        DB::table('settings')
            ->where('key', 'attendance.punch_early_minutes')
            ->whereRaw('value::text = ?', ['120'])
            ->update(['value' => json_encode(15), 'description' => 'How early before shift start an employee may time in. Pay still starts at shift start.', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('key', 'attendance.punch_early_minutes')
            ->whereRaw('value::text = ?', ['15'])
            ->update(['value' => json_encode(120), 'updated_at' => now()]);
    }
};
