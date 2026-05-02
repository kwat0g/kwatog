<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\CRM\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Sprint 6 — Task 47.
 * Eight demo finished-good products per docs/SEEDS.md §14.
 */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['part_number' => 'WB-001', 'name' => 'Wiper Bushing (Standard)',     'standard_cost' => 18.50],
            ['part_number' => 'WB-002', 'name' => 'Wiper Bushing (Heavy Duty)',   'standard_cost' => 24.50],
            ['part_number' => 'PC-001', 'name' => 'Pivot Cap Cover Type A',       'standard_cost' => 28.00],
            ['part_number' => 'PC-002', 'name' => 'Pivot Cap Cover Type B',       'standard_cost' => 28.50],
            ['part_number' => 'RC-001', 'name' => 'Relay Cover Standard',         'standard_cost' => 38.00],
            ['part_number' => 'BB-001', 'name' => 'Wiper Motor Bobbin',           'standard_cost' => 22.00],
            ['part_number' => 'BU-001', 'name' => 'Windshield Wiper Bushing',     'standard_cost' => 21.00],
            ['part_number' => 'RC-002', 'name' => 'Relay Cover Large',            'standard_cost' => 56.00],
        ];

        foreach ($products as $p) {
            Product::firstOrCreate(
                ['part_number' => $p['part_number']],
                [
                    'name'            => $p['name'],
                    'description'     => null,
                    'unit_of_measure' => 'pcs',
                    'standard_cost'   => $p['standard_cost'],
                    'is_active'       => true,
                ]
            );
        }

        $this->command?->info('Seeded ' . count($products) . ' demo products.');
    }
}
