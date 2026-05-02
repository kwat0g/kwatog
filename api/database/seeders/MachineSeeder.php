<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\MRP\Models\Machine;
use Illuminate\Database\Seeder;

/**
 * Sprint 6 — Task 50.
 * 12 demo injection-molding machines per the thesis fact pattern (Ogami
 * Cavite plant). Tonnages and operator ratios are representative; locations
 * and OEM detail intentionally omitted.
 */
class MachineSeeder extends Seeder
{
    private const MACHINES = [
        ['code' => 'IMM-01', 'name' => 'Toshiba EC100SX',  'tonnage' => 100, 'ops' => 1.0, 'hrs' => 16.0],
        ['code' => 'IMM-02', 'name' => 'Toshiba EC100SX',  'tonnage' => 100, 'ops' => 1.0, 'hrs' => 16.0],
        ['code' => 'IMM-03', 'name' => 'Sumitomo SE130',   'tonnage' => 130, 'ops' => 1.0, 'hrs' => 16.0],
        ['code' => 'IMM-04', 'name' => 'Sumitomo SE130',   'tonnage' => 130, 'ops' => 1.0, 'hrs' => 16.0],
        ['code' => 'IMM-05', 'name' => 'Nissei NEX180',    'tonnage' => 180, 'ops' => 1.0, 'hrs' => 16.0],
        ['code' => 'IMM-06', 'name' => 'Nissei NEX180',    'tonnage' => 180, 'ops' => 1.0, 'hrs' => 16.0],
        ['code' => 'IMM-07', 'name' => 'Fanuc Roboshot',   'tonnage' => 220, 'ops' => 1.5, 'hrs' => 16.0],
        ['code' => 'IMM-08', 'name' => 'Fanuc Roboshot',   'tonnage' => 220, 'ops' => 1.5, 'hrs' => 16.0],
        ['code' => 'IMM-09', 'name' => 'JSW J280AD',       'tonnage' => 280, 'ops' => 2.0, 'hrs' => 16.0],
        ['code' => 'IMM-10', 'name' => 'JSW J280AD',       'tonnage' => 280, 'ops' => 2.0, 'hrs' => 16.0],
        ['code' => 'IMM-11', 'name' => 'Toshiba EC450',    'tonnage' => 450, 'ops' => 2.0, 'hrs' => 16.0],
        ['code' => 'IMM-12', 'name' => 'Toshiba EC650',    'tonnage' => 650, 'ops' => 2.0, 'hrs' => 16.0],
    ];

    public function run(): void
    {
        $created = 0;
        foreach (self::MACHINES as $m) {
            $made = Machine::firstOrCreate(
                ['machine_code' => $m['code']],
                [
                    'name'                    => $m['name'],
                    'tonnage'                 => $m['tonnage'],
                    'machine_type'            => 'injection_molder',
                    'operators_required'      => $m['ops'],
                    'available_hours_per_day' => $m['hrs'],
                    'status'                  => 'idle',
                ]
            );
            if ($made->wasRecentlyCreated) $created++;
        }
        $this->command?->info("Seeded {$created} machines (skipped " . (count(self::MACHINES) - $created) . " existing).");
    }
}
