<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\MRP\Models\Bom;
use Illuminate\Database\Seeder;

/**
 * Sprint 6 — Task 49.
 * One BOM per finished-good product, raw material rows mapped per docs/SEEDS.md §14.
 *
 * Resins / colorants are stored in kilograms (per InventoryItemSeeder), so a
 * 15g consumption translates to 0.0150 kg quantity_per_unit.
 */
class BomSeeder extends Seeder
{
    /**
     * partNumber → [ [code, qty, unit, waste], ... ]
     */
    private const MATRIX = [
        'WB-001' => [
            ['RM-001', '0.0150', 'kg',  '5.00'],   // 15g Resin A
            ['RM-010', '0.0020', 'kg',  '2.00'],   // 2g Black Colorant
            ['PKG-001', '1.000', 'pcs', '0.00'],   // Poly Bag
        ],
        'WB-002' => [
            ['RM-001', '0.0200', 'kg',  '5.00'],
            ['RM-010', '0.0030', 'kg',  '2.00'],
            ['PKG-001', '1.000', 'pcs', '0.00'],
        ],
        'PC-001' => [
            ['RM-002', '0.0250', 'kg',  '5.00'],
            ['RM-011', '0.0030', 'kg',  '2.00'],
            ['PKG-001', '1.000', 'pcs', '0.00'],
        ],
        'PC-002' => [
            ['RM-002', '0.0250', 'kg',  '5.00'],
            ['RM-012', '0.0030', 'kg',  '2.00'],
            ['PKG-001', '1.000', 'pcs', '0.00'],
        ],
        'RC-001' => [
            ['RM-003', '0.0300', 'kg',  '5.00'],
            ['RM-010', '0.0020', 'kg',  '2.00'],
            ['RM-050', '1.000',  'pcs', '0.50'],   // Small Metal Insert
            ['PKG-001', '1.000', 'pcs', '0.00'],
        ],
        'BB-001' => [
            ['RM-004', '0.0120', 'kg',  '5.00'],
            ['RM-052', '1.000',  'pcs', '0.50'],   // Metal Core
            ['PKG-001', '1.000', 'pcs', '0.00'],
        ],
        'BU-001' => [
            ['RM-001', '0.0180', 'kg',  '5.00'],
            ['RM-011', '0.0020', 'kg',  '2.00'],
            ['PKG-001', '1.000', 'pcs', '0.00'],
        ],
        'RC-002' => [
            ['RM-003', '0.0450', 'kg',  '5.00'],
            ['RM-010', '0.0030', 'kg',  '2.00'],
            ['RM-051', '2.000',  'pcs', '0.50'],   // 2 Large Metal Inserts
            ['PKG-001', '1.000', 'pcs', '0.00'],
        ],
    ];

    public function run(): void
    {
        $created = 0;

        foreach (self::MATRIX as $partNumber => $rows) {
            $product = Product::where('part_number', $partNumber)->first();
            if (! $product) {
                $this->command?->warn("Product '{$partNumber}' not found — run ProductSeeder first.");
                continue;
            }

            // Skip if active BOM already exists (idempotent re-run).
            $existing = Bom::where('product_id', $product->id)->where('is_active', true)->exists();
            if ($existing) {
                continue;
            }

            $bom = Bom::create([
                'product_id' => $product->id,
                'version'    => 1,
                'is_active'  => true,
            ]);

            foreach ($rows as $idx => [$code, $qty, $unit, $waste]) {
                $item = Item::where('code', $code)->first();
                if (! $item) {
                    $this->command?->warn("Item '{$code}' not found — run InventoryItemSeeder first.");
                    continue;
                }
                $bom->items()->create([
                    'item_id'           => $item->id,
                    'quantity_per_unit' => $qty,
                    'unit'              => $unit,
                    'waste_factor'      => $waste,
                    'sort_order'        => $idx,
                ]);
            }
            $created++;
        }

        $this->command?->info("Seeded {$created} BOMs.");
    }
}
