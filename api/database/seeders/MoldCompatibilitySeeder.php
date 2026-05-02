<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use Illuminate\Database\Seeder;

/**
 * Sprint 6 — Task 50.
 * Tonnage-driven compatibility: each mold can run on any machine whose
 * tonnage is within ±50% of a notional clamping force needed for the part.
 * Concrete mappings are kept simple but plausible; PPC head can override
 * via UI (Task 50 sync endpoint).
 */
class MoldCompatibilitySeeder extends Seeder
{
    /**
     * Mold code prefix → tonnage band the mold accepts.
     * Each mold maps to all machines whose tonnage is in the range.
     */
    private const TONNAGE_BAND = [
        'M-WB-' => [80, 220],     // small bushings — 100t and 130t machines
        'M-PC-' => [100, 280],    // pivot caps — mid-range
        'M-RC-' => [180, 450],    // relay covers — bigger
        'M-BB-' => [80, 180],     // bobbins — small
        'M-BU-' => [100, 220],    // wiper bushing — small/mid
    ];

    public function run(): void
    {
        $machines = Machine::all();
        $created = 0;

        Mold::all()->each(function (Mold $mold) use ($machines, &$created) {
            $prefix = substr($mold->mold_code, 0, 5);
            [$min, $max] = self::TONNAGE_BAND[$prefix] ?? [0, 9999];
            $compatible = $machines->filter(fn ($m) => $m->tonnage !== null
                                                       && $m->tonnage >= $min
                                                       && $m->tonnage <= $max);
            foreach ($compatible as $machine) {
                if (! $mold->compatibleMachines()->where('machine_id', $machine->id)->exists()) {
                    $mold->compatibleMachines()->attach($machine->id);
                    $created++;
                }
            }
        });

        $this->command?->info("Seeded {$created} mold-machine compatibility links.");
    }
}
