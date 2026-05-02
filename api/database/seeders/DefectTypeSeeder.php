<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Production\Models\DefectType;
use Illuminate\Database\Seeder;

/**
 * Sprint 6 — Task 51.
 * 11 defect codes per docs/SEEDS.md §7 — injection-molding industry-standard.
 */
class DefectTypeSeeder extends Seeder
{
    private const DEFECTS = [
        ['code' => 'SHRT',    'name' => 'Short Shot',     'description' => 'Incomplete fill of cavity.'],
        ['code' => 'FLSH',    'name' => 'Flash',          'description' => 'Excess material at parting line.'],
        ['code' => 'BURN',    'name' => 'Burn Marks',     'description' => 'Discoloration from trapped air or overheating.'],
        ['code' => 'DIM',     'name' => 'Dimensional',    'description' => 'Out-of-spec dimensions.'],
        ['code' => 'COLOR',   'name' => 'Color Mismatch', 'description' => 'Color does not match standard.'],
        ['code' => 'CRACK',   'name' => 'Cracks',         'description' => 'Visible cracks in part.'],
        ['code' => 'WARP',    'name' => 'Warpage',        'description' => 'Distorted from intended geometry.'],
        ['code' => 'BUBBLE',  'name' => 'Air Bubbles',    'description' => 'Internal bubbles / voids.'],
        ['code' => 'INC',     'name' => 'Inclusions',     'description' => 'Foreign matter embedded in part.'],
        ['code' => 'MISMATCH','name' => 'Mold Mismatch',  'description' => 'Mold halves misaligned.'],
        ['code' => 'OTHER',   'name' => 'Other',          'description' => 'Catch-all.'],
    ];

    public function run(): void
    {
        foreach (self::DEFECTS as $d) {
            DefectType::firstOrCreate(['code' => $d['code']], [
                'name' => $d['name'],
                'description' => $d['description'],
                'is_active' => true,
            ]);
        }
        $this->command?->info('Seeded ' . count(self::DEFECTS) . ' defect types.');
    }
}
