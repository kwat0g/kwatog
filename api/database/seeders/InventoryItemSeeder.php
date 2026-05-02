<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCategory;
use Illuminate\Database\Seeder;

class InventoryItemSeeder extends Seeder
{
    public function run(): void
    {
        // Root categories.
        $rawMaterials  = ItemCategory::firstOrCreate(['name' => 'Raw Materials']);
        $packaging     = ItemCategory::firstOrCreate(['name' => 'Packaging']);
        $finishedGoods = ItemCategory::firstOrCreate(['name' => 'Finished Goods']);
        $spareParts    = ItemCategory::firstOrCreate(['name' => 'Spare Parts']);

        // Sub-categories under Raw Materials.
        $resins    = ItemCategory::firstOrCreate(['name' => 'Resins',    'parent_id' => $rawMaterials->id]);
        $colorants = ItemCategory::firstOrCreate(['name' => 'Colorants', 'parent_id' => $rawMaterials->id]);
        $inserts   = ItemCategory::firstOrCreate(['name' => 'Metal Inserts', 'parent_id' => $rawMaterials->id]);

        // Items per docs/SEEDS.md DEMO RAW MATERIALS (12).
        $items = [
            // Resins — reorder 500kg, lead 14 days, MOQ 25kg bag
            ['code' => 'RM-001', 'name' => 'Plastic Resin Type A (ABS)', 'cat' => $resins, 'type' => 'raw_material', 'uom' => 'kg', 'cost' => '120.0000', 'reorder' => '500.000', 'safety' => '200.000', 'moq' => '25.000', 'lead' => 14],
            ['code' => 'RM-002', 'name' => 'Plastic Resin Type B (PP)',  'cat' => $resins, 'type' => 'raw_material', 'uom' => 'kg', 'cost' => '95.0000',  'reorder' => '500.000', 'safety' => '200.000', 'moq' => '25.000', 'lead' => 14],
            ['code' => 'RM-003', 'name' => 'Plastic Resin Type C (PA)',  'cat' => $resins, 'type' => 'raw_material', 'uom' => 'kg', 'cost' => '150.0000', 'reorder' => '300.000', 'safety' => '120.000', 'moq' => '25.000', 'lead' => 14, 'critical' => true],
            ['code' => 'RM-004', 'name' => 'Plastic Resin Type D (POM)', 'cat' => $resins, 'type' => 'raw_material', 'uom' => 'kg', 'cost' => '180.0000', 'reorder' => '300.000', 'safety' => '100.000', 'moq' => '25.000', 'lead' => 14],
            // Colorants — reorder 50kg, lead 7 days
            ['code' => 'RM-010', 'name' => 'Black Colorant', 'cat' => $colorants, 'type' => 'raw_material', 'uom' => 'kg', 'cost' => '250.0000', 'reorder' => '50.000', 'safety' => '20.000', 'moq' => '5.000',  'lead' => 7],
            ['code' => 'RM-011', 'name' => 'White Colorant', 'cat' => $colorants, 'type' => 'raw_material', 'uom' => 'kg', 'cost' => '280.0000', 'reorder' => '50.000', 'safety' => '20.000', 'moq' => '5.000',  'lead' => 7],
            ['code' => 'RM-012', 'name' => 'Gray Colorant',  'cat' => $colorants, 'type' => 'raw_material', 'uom' => 'kg', 'cost' => '260.0000', 'reorder' => '40.000', 'safety' => '15.000', 'moq' => '5.000',  'lead' => 7],
            // Metal Inserts — reorder 5000pcs, lead 21 days
            ['code' => 'RM-050', 'name' => 'Small Metal Insert', 'cat' => $inserts, 'type' => 'raw_material', 'uom' => 'pcs', 'cost' => '5.5000',  'reorder' => '5000.000', 'safety' => '2000.000', 'moq' => '1000.000', 'lead' => 21],
            ['code' => 'RM-051', 'name' => 'Large Metal Insert', 'cat' => $inserts, 'type' => 'raw_material', 'uom' => 'pcs', 'cost' => '8.0000',  'reorder' => '4000.000', 'safety' => '1500.000', 'moq' => '1000.000', 'lead' => 21],
            ['code' => 'RM-052', 'name' => 'Metal Core (Bobbin)','cat' => $inserts, 'type' => 'raw_material', 'uom' => 'pcs', 'cost' => '12.0000', 'reorder' => '3000.000', 'safety' => '1000.000', 'moq' => '500.000',  'lead' => 21, 'critical' => true],
            // Packaging — reorder 2000pcs, lead 5 days
            ['code' => 'PKG-001', 'name' => 'Standard Poly Bag',     'cat' => $packaging, 'type' => 'packaging', 'uom' => 'pcs', 'cost' => '0.5000',  'reorder' => '2000.000', 'safety' => '500.000', 'moq' => '500.000', 'lead' => 5],
            ['code' => 'PKG-002', 'name' => 'Shipping Box (50 pcs)', 'cat' => $packaging, 'type' => 'packaging', 'uom' => 'pcs', 'cost' => '15.0000', 'reorder' => '1000.000', 'safety' => '300.000', 'moq' => '100.000', 'lead' => 5],
        ];

        foreach ($items as $row) {
            Item::firstOrCreate(
                ['code' => $row['code']],
                [
                    'name'                   => $row['name'],
                    'category_id'            => $row['cat']->id,
                    'item_type'              => $row['type'],
                    'unit_of_measure'        => $row['uom'],
                    'standard_cost'          => $row['cost'],
                    'reorder_method'         => 'fixed_quantity',
                    'reorder_point'          => $row['reorder'],
                    'safety_stock'           => $row['safety'],
                    'minimum_order_quantity' => $row['moq'],
                    'lead_time_days'         => $row['lead'],
                    'is_critical'            => $row['critical'] ?? false,
                    'is_active'              => true,
                ],
            );
        }

        $this->command?->info('Inventory: 4 root categories, 3 sub-categories, 12 items seeded.');
    }
}
