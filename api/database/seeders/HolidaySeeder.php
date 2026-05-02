<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Attendance\Models\Holiday;
use Illuminate\Database\Seeder;

class HolidaySeeder extends Seeder
{
    public function run(): void
    {
        $holidays = [
            ['date' => '2026-01-01', 'name' => "New Year's Day",                  'type' => 'regular'],
            ['date' => '2026-02-17', 'name' => 'Chinese New Year',                'type' => 'special_non_working'],
            ['date' => '2026-02-25', 'name' => 'EDSA Revolution Anniversary',     'type' => 'special_non_working'],
            ['date' => '2026-03-20', 'name' => "Eid'l Fitr",                      'type' => 'regular'],
            ['date' => '2026-04-02', 'name' => 'Maundy Thursday',                 'type' => 'regular'],
            ['date' => '2026-04-03', 'name' => 'Good Friday',                     'type' => 'regular'],
            ['date' => '2026-04-04', 'name' => 'Black Saturday',                  'type' => 'special_non_working'],
            ['date' => '2026-04-09', 'name' => 'Araw ng Kagitingan',              'type' => 'regular'],
            ['date' => '2026-05-01', 'name' => 'Labor Day',                       'type' => 'regular'],
            ['date' => '2026-05-27', 'name' => "Eid'l Adha",                      'type' => 'regular'],
            ['date' => '2026-06-12', 'name' => 'Independence Day',                'type' => 'regular'],
            ['date' => '2026-08-21', 'name' => 'Ninoy Aquino Day',                'type' => 'special_non_working'],
            ['date' => '2026-08-31', 'name' => 'National Heroes Day',             'type' => 'regular'],
            ['date' => '2026-11-01', 'name' => "All Saints' Day",                 'type' => 'special_non_working'],
            ['date' => '2026-11-02', 'name' => "All Souls' Day",                  'type' => 'special_non_working'],
            ['date' => '2026-11-30', 'name' => 'Bonifacio Day',                   'type' => 'regular'],
            ['date' => '2026-12-08', 'name' => 'Feast of Immaculate Conception',  'type' => 'special_non_working'],
            ['date' => '2026-12-24', 'name' => 'Christmas Eve',                   'type' => 'special_non_working'],
            ['date' => '2026-12-25', 'name' => 'Christmas Day',                   'type' => 'regular'],
            ['date' => '2026-12-30', 'name' => 'Rizal Day',                       'type' => 'regular'],
            ['date' => '2026-12-31', 'name' => 'Last Day of the Year',            'type' => 'special_non_working'],
        ];

        foreach ($holidays as $h) {
            Holiday::updateOrCreate(
                ['date' => $h['date'], 'name' => $h['name']],
                ['type' => $h['type'], 'is_recurring' => false],
            );
        }

        $this->command?->info('Holidays (PH 2026) seeded.');
    }
}
