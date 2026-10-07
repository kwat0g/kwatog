<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SETTINGS = [
        ['attendance.punch_early_out_grace_minutes', 15, 'Punch: early time-out grace (min)', 'How many minutes before shift end an employee may time out.'],
        ['attendance.punch_min_work_minutes', 30, 'Punch: minimum work before time-out (min)', 'Minimum minutes between the latest time-in punch and a time-out.'],
        ['attendance.punch_late_in_cutoff_minutes', 240, 'Punch: late time-in cutoff (min)', 'The first time-in of the day is refused later than this many minutes after shift start.'],
    ];

    public function up(): void
    {
        foreach (self::SETTINGS as [$key, $value, $label, $description]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($value),
                'group' => 'attendance',
                'label' => $label,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_column(self::SETTINGS, 0))->delete();
    }
};
