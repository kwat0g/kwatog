<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Inventory\Models\Uom;
use Illuminate\Database\Seeder;

/**
 * OGAMI-004 — canonical Unit-of-Measure catalog.
 *
 * NOTE FOR ORCHESTRATOR: this seeder is NOT yet registered in DatabaseSeeder.
 * Add `$this->call(UomSeeder::class);` (before any conversion/demo seeders).
 */
class UomSeeder extends Seeder
{
    public function run(): void
    {
        $uoms = [
            ['code' => 'KG',     'name' => 'Kilogram'],
            ['code' => 'G',      'name' => 'Gram'],
            ['code' => 'PCS',    'name' => 'Pieces'],
            ['code' => 'BAG',    'name' => 'Bag'],
            ['code' => 'PALLET', 'name' => 'Pallet'],
            ['code' => 'BOX',    'name' => 'Box'],
            ['code' => 'L',      'name' => 'Liter'],
            ['code' => 'ROLL',   'name' => 'Roll'],
        ];

        foreach ($uoms as $u) {
            Uom::firstOrCreate(['code' => $u['code']], ['name' => $u['name']]);
        }

        $this->command?->info('UOM catalog seeded ('.count($uoms).' units).');
    }
}
