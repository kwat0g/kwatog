<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Common\Support\ProductionAssertions;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCategory;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Additive thesis-demo fixtures for product pricing, supplier sourcing, and
 * maintenance spare-part availability. Safe to run after ordinary demo setup.
 */
class ThesisDemoSeeder extends Seeder
{
    /** @var array<string, array{base:string, minimum:string}> */
    private const FINISHED_GOOD_PRICES = [
        'WB-001' => ['base' => '23.00', 'minimum' => '20.50'],
        'WB-002' => ['base' => '30.50', 'minimum' => '27.00'],
        'PC-001' => ['base' => '35.00', 'minimum' => '31.00'],
        'PC-002' => ['base' => '35.50', 'minimum' => '31.50'],
        'RC-001' => ['base' => '47.50', 'minimum' => '42.00'],
        'BB-001' => ['base' => '27.50', 'minimum' => '24.00'],
        'BU-001' => ['base' => '26.50', 'minimum' => '23.00'],
        'RC-002' => ['base' => '70.00', 'minimum' => '61.50'],
    ];

    /** @var array<string, array{quantity:string, location:string}> */
    private const FINISHED_GOOD_STOCK = [
        'WB-001' => ['quantity' => '1200.000', 'location' => 'C-01'],
        'WB-002' => ['quantity' => '800.000', 'location' => 'C-02'],
        'PC-001' => ['quantity' => '600.000', 'location' => 'C-03'],
        'PC-002' => ['quantity' => '600.000', 'location' => 'C-04'],
        'RC-001' => ['quantity' => '400.000', 'location' => 'C-05'],
        'BB-001' => ['quantity' => '900.000', 'location' => 'C-06'],
        'BU-001' => ['quantity' => '1000.000', 'location' => 'C-07'],
        'RC-002' => ['quantity' => '300.000', 'location' => 'C-08'],
    ];

    /** @var array<string, array{vendor:string, lead_time_days:int}> */
    private const SUPPLIER_MAP = [
        'RM-001' => ['vendor' => 'Megaplast Industries Corp.', 'lead_time_days' => 14],
        'RM-002' => ['vendor' => 'Asia Pacific Polymers, Inc.', 'lead_time_days' => 14],
        'RM-003' => ['vendor' => 'Asia Pacific Polymers, Inc.', 'lead_time_days' => 14],
        'RM-004' => ['vendor' => 'Asia Pacific Polymers, Inc.', 'lead_time_days' => 14],
        'RM-010' => ['vendor' => 'Oriental Chemical Corp.', 'lead_time_days' => 7],
        'RM-011' => ['vendor' => 'Oriental Chemical Corp.', 'lead_time_days' => 7],
        'RM-012' => ['vendor' => 'Oriental Chemical Corp.', 'lead_time_days' => 7],
        'RM-050' => ['vendor' => 'Cavite Precision Tools & Dies', 'lead_time_days' => 21],
        'RM-051' => ['vendor' => 'Cavite Precision Tools & Dies', 'lead_time_days' => 21],
        'RM-052' => ['vendor' => 'Cavite Precision Tools & Dies', 'lead_time_days' => 21],
        'PKG-001' => ['vendor' => 'Manila Industrial Packaging', 'lead_time_days' => 5],
        'PKG-002' => ['vendor' => 'Manila Industrial Packaging', 'lead_time_days' => 5],
    ];

    /** @var array<string, array{name:string, vendor:string, cost:string, quantity:string, lead_time_days:int}> */
    private const SPARE_PARTS = [
        'SP-HYD-001' => [
            'name' => 'Hydraulic Oil Filter — Injection Molder',
            'vendor' => 'Cavite Precision Tools & Dies',
            'cost' => '1850.0000',
            'quantity' => '12.000',
            'lead_time_days' => 7,
        ],
        'SP-HTR-001' => [
            'name' => 'Barrel Heater Band 220V — Injection Molder',
            'vendor' => 'Tooling Pro Manufacturing',
            'cost' => '1200.0000',
            'quantity' => '8.000',
            'lead_time_days' => 14,
        ],
    ];

    public function run(): void
    {
        ProductionAssertions::assertDemoSeedingAllowed();

        $this->call(ManufacturedProductInventorySeeder::class);
        $this->seedFinishedGoodPrices();
        $this->seedFinishedGoodStock();
        $this->seedMaintenanceSpareParts();
        $this->seedSupplierLinks();

        $this->command?->info('Thesis demo fixtures seeded.');
    }

    private function seedFinishedGoodPrices(): void
    {
        foreach (self::FINISHED_GOOD_PRICES as $partNumber => $prices) {
            $product = Product::query()->where('part_number', $partNumber)->first();
            if (! $product) {
                continue;
            }

            if ($product->base_selling_price === null && $product->minimum_selling_price === null) {
                $product->forceFill([
                    'base_selling_price' => $prices['base'],
                    'minimum_selling_price' => $prices['minimum'],
                ])->save();
            }
        }
    }

    private function seedSupplierLinks(): void
    {
        foreach (self::SUPPLIER_MAP as $itemCode => $supplier) {
            $item = Item::query()->where('code', $itemCode)->first();
            $vendor = Vendor::query()->where('name', $supplier['vendor'])->first();
            if (! $item || ! $vendor) {
                continue;
            }

            $this->ensurePreferredSupplier($item, $vendor, $supplier['lead_time_days']);
        }

        foreach (self::SPARE_PARTS as $itemCode => $sparePart) {
            $item = Item::query()->where('code', $itemCode)->first();
            $vendor = Vendor::query()->where('name', $sparePart['vendor'])->first();
            if (! $item || ! $vendor) {
                continue;
            }

            $this->ensurePreferredSupplier($item, $vendor, $sparePart['lead_time_days']);
        }
    }

    private function seedFinishedGoodStock(): void
    {
        $zone = WarehouseZone::query()->where('zone_type', 'finished_goods')->first();
        if (! $zone) {
            $this->command?->warn('Thesis demo finished-goods stock skipped: run the warehouse reference seeder first.');
            return;
        }

        foreach (self::FINISHED_GOOD_STOCK as $code => $data) {
            $item = Item::query()->where('code', $code)->where('item_type', 'finished_good')->first();
            $location = WarehouseLocation::query()
                ->where('zone_id', $zone->id)
                ->where('code', $data['location'])
                ->first();
            if (! $item || ! $location) {
                continue;
            }

            $level = StockLevel::query()
                ->where('item_id', $item->id)
                ->where('location_id', $location->id)
                ->first();
            if (! $level) {
                $hasExistingInventory = StockLevel::query()->where('item_id', $item->id)->exists()
                    || DB::table('stock_movements')->where('item_id', $item->id)->exists();
                if ($hasExistingInventory) {
                    continue;
                }

                $level = StockLevel::query()->create([
                    'item_id' => $item->id,
                    'location_id' => $location->id,
                    'quantity' => $data['quantity'],
                    'reserved_quantity' => '0.000',
                    'weighted_avg_cost' => $item->standard_cost,
                    'lock_version' => 0,
                ]);
            }

            $this->seedOpeningMovement($item, $location, $level, 'Opening balance — thesis demo finished goods');
        }
    }

    private function ensurePreferredSupplier(Item $item, Vendor $vendor, int $leadTimeDays): void
    {
        // The database enforces at most one live preferred supplier per item.
        ApprovedSupplier::query()
            ->where('item_id', $item->id)
            ->where('is_preferred', true)
            ->where('vendor_id', '!=', $vendor->id)
            ->update(['is_preferred' => false]);

        $link = ApprovedSupplier::withTrashed()->firstOrNew([
            'item_id' => $item->id,
            'vendor_id' => $vendor->id,
        ]);
        if ($link->exists && $link->trashed()) {
            $link->restore();
        }

        $link->forceFill([
            'is_preferred' => true,
            'qualification_status' => ApprovedSupplier::QUALIFICATION_APPROVED,
            'lead_time_days' => $leadTimeDays,
        ])->save();

        $metadata = [];
        if (! $link->supplier_item_code) {
            $metadata['supplier_item_code'] = 'DEMO-'.$item->code;
        }
        if (! $link->supplier_item_name) {
            $metadata['supplier_item_name'] = $item->name;
        }
        if (! $link->order_uom) {
            $metadata['order_uom'] = $item->unit_of_measure;
        }
        if ($link->base_qty_per_order_unit === null) {
            $metadata['base_qty_per_order_unit'] = '1.0000';
        }
        if ($metadata !== []) {
            $link->forceFill($metadata)->save();
        }
    }

    private function seedMaintenanceSpareParts(): void
    {
        $category = ItemCategory::query()->where('name', 'Spare Parts')->first();
        $zone = WarehouseZone::query()->where('zone_type', 'spare_parts')->first();

        if (! $category || ! $zone) {
            $this->command?->warn('Thesis demo spare parts skipped: run the reference-data seeders first.');
            return;
        }

        foreach (self::SPARE_PARTS as $code => $data) {
            $item = Item::withTrashed()->firstOrNew(['code' => $code]);
            if ($item->exists && $item->trashed()) {
                $item->restore();
            }
            if (! $item->exists) {
                $item->forceFill([
                    'name' => $data['name'],
                    'description' => 'Fictional stocked maintenance spare part for the thesis demo.',
                    'category_id' => $category->id,
                    'item_type' => 'spare_part',
                    'unit_of_measure' => 'pcs',
                    'standard_cost' => $data['cost'],
                    'reorder_method' => 'fixed_quantity',
                    'reorder_point' => '4.000',
                    'safety_stock' => '2.000',
                    'minimum_order_quantity' => '1.000',
                    'lead_time_days' => $data['lead_time_days'],
                    'is_critical' => false,
                    'is_active' => true,
                ])->save();
            }

            $location = WarehouseLocation::query()
                ->where('zone_id', $zone->id)
                ->where('code', $code === 'SP-HYD-001' ? 'D-01' : 'D-02')
                ->first();
            if (! $location) {
                continue;
            }

            $level = StockLevel::query()
                ->where('item_id', $item->id)
                ->where('location_id', $location->id)
                ->first();
            if (! $level) {
                $hasExistingInventory = StockLevel::query()->where('item_id', $item->id)->exists()
                    || DB::table('stock_movements')->where('item_id', $item->id)->exists();
                if ($hasExistingInventory) {
                    continue;
                }

                $level = StockLevel::query()->create([
                    'item_id' => $item->id,
                    'location_id' => $location->id,
                    'quantity' => $data['quantity'],
                    'reserved_quantity' => '0.000',
                    'weighted_avg_cost' => $item->standard_cost,
                    'lock_version' => 0,
                ]);
            }

            $this->seedOpeningMovement($item, $location, $level, 'Opening balance — thesis demo spare part');
        }
    }

    private function seedOpeningMovement(Item $item, WarehouseLocation $location, StockLevel $level, string $remarks): void
    {
        $movementExists = DB::table('stock_movements')
            ->where('item_id', $item->id)
            ->exists();
        if ($movementExists) {
            return;
        }

        $adminId = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.slug', 'system_admin')
            ->orderBy('users.id')
            ->value('users.id');
        $quantity = (string) $level->quantity;
        $unitCost = (string) $item->standard_cost;

        DB::table('stock_movements')->insert([
            'item_id' => $item->id,
            'to_location_id' => $location->id,
            'movement_type' => 'opening',
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => round((float) $quantity * (float) $unitCost, 2),
            'remarks' => $remarks,
            'created_by' => $adminId,
            'created_at' => now(),
        ]);
    }
}
