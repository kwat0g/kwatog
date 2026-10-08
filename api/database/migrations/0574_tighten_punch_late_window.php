<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Late time-out window: 240 -> 120 minutes. Only moves the untouched default. */
    public function up(): void
    {
        DB::table('settings')
            ->where('key', 'attendance.punch_late_minutes')
            ->whereRaw('value::text = ?', ['240'])
            ->update(['value' => json_encode(120), 'description' => 'How late after shift end an employee may still time out. Approved overtime extends it by the approved hours.', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('settings')
            ->where('key', 'attendance.punch_late_minutes')
            ->whereRaw('value::text = ?', ['120'])
            ->update(['value' => json_encode(240), 'updated_at' => now()]);
    }
};
