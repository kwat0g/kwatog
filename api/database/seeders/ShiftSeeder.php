<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Attendance\Models\Shift;
use Illuminate\Database\Seeder;

class ShiftSeeder extends Seeder
{
    public function run(): void
    {
        $shifts = [
            ['name' => 'Day Shift',      'start_time' => '06:00', 'end_time' => '14:00', 'break_minutes' => 30, 'is_night_shift' => false, 'is_extended' => false, 'auto_ot_hours' => null],
            ['name' => 'Extended Day',   'start_time' => '06:00', 'end_time' => '18:00', 'break_minutes' => 30, 'is_night_shift' => false, 'is_extended' => true,  'auto_ot_hours' => 4.0],
            ['name' => 'Night Shift',    'start_time' => '18:00', 'end_time' => '06:00', 'break_minutes' => 30, 'is_night_shift' => true,  'is_extended' => false, 'auto_ot_hours' => null],
            ['name' => 'Office Hours',   'start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 60, 'is_night_shift' => false, 'is_extended' => false, 'auto_ot_hours' => null],
        ];

        foreach ($shifts as $s) {
            Shift::updateOrCreate(['name' => $s['name']], $s + ['is_active' => true]);
        }
        $this->command?->info('Shifts seeded.');
    }
}
