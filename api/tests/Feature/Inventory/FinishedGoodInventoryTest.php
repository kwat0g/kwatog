<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Services\ProductService;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCategory;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\FinishedGoodInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinishedGoodInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Uom::create(['code' => 'PCS', 'name' => 'Pieces']);
    }

    public function test_active_product_creates_one_finished_good_without_creating_stock(): void
    {
        $product = app(ProductService::class)->create([
            'part_number' => 'auto-fg', 'name' => 'Automatic product',
            'unit_of_measure' => 'pcs', 'standard_cost' => '25.50', 'is_active' => true,
        ]);
        $item = app(FinishedGoodInventoryService::class)->ensureItem($product);
        $this->assertSame('AUTO-FG', $item->code);
        $this->assertSame('Automatic product', $item->name);
        $this->assertSame('PCS', $item->unit_of_measure);
        $this->assertSame(ItemType::FinishedGood, $item->item_type);
        $this->assertSame('25.5000', $item->standard_cost);
        $this->assertSame('finished_good', $item->category->item_type);
        $this->assertSame(1, Item::count());
        $this->assertSame(1, ItemCategory::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, $item->stockLevels()->count());
    }

    public function test_inactive_product_provisions_only_when_activated(): void
    {
        $product = app(ProductService::class)->create([
            'part_number' => 'DEFERRED-FG', 'name' => 'Deferred product',
            'unit_of_measure' => 'PCS', 'standard_cost' => '10', 'is_active' => false,
        ]);
        $this->assertSame(0, Item::count());
        app(ProductService::class)->update($product, ['is_active' => true]);
        $this->assertSame(1, Item::where('code', 'DEFERRED-FG')->count());
    }

    public function test_existing_matching_item_is_reused_without_overwriting_valuation(): void
    {
        $item = Item::factory()->create(['code' => 'REUSE-FG', 'item_type' => 'finished_good', 'unit_of_measure' => 'PCS', 'standard_cost' => '8.00']);
        $product = app(ProductService::class)->create([
            'part_number' => 'REUSE-FG', 'name' => 'Reused product',
            'unit_of_measure' => 'PCS', 'standard_cost' => '25', 'is_active' => true,
        ]);
        $this->assertSame($item->id, app(FinishedGoodInventoryService::class)->ensureItem($product)->id);
        $this->assertSame('8.0000', $item->fresh()->standard_cost);
        $this->assertSame(1, Item::count());
    }

    public function test_conflicting_existing_items_are_never_overwritten_or_duplicated(): void
    {
        foreach ([['is_active' => false], ['item_type' => 'raw_material'], ['unit_of_measure' => 'KG'], ['code' => 'conflict-fg']] as $conflict) {
            $item = Item::factory()->create(array_merge(['code' => 'CONFLICT-FG', 'item_type' => 'finished_good', 'unit_of_measure' => 'PCS'], $conflict));
            try {
                app(ProductService::class)->create(['part_number' => 'CONFLICT-FG', 'name' => 'Conflict', 'unit_of_measure' => 'PCS', 'is_active' => true]);
                $this->fail('Conflicting inventory must block product creation.');
            } catch (BusinessRuleException $error) {
                $this->assertStringContainsString('Warehouse', $error->getMessage());
            }
            $this->assertSame(0, Product::count());
            $this->assertSame(1, Item::withTrashed()->count());
            $item->forceDelete();
        }
        $item = Item::factory()->create(['code' => 'ARCHIVED-FG', 'item_type' => 'finished_good', 'unit_of_measure' => 'PCS']);
        $item->delete();
        $product = Product::factory()->create(['part_number' => 'ARCHIVED-FG', 'unit_of_measure' => 'PCS']);
        $this->expectException(BusinessRuleException::class);
        app(FinishedGoodInventoryService::class)->ensureItem($product);
    }

    public function test_location_skips_inactive_warehouses_and_blocked_bins(): void
    {
        $zone = WarehouseZone::factory()->create(['zone_type' => 'finished_goods']);
        WarehouseLocation::factory()->create(['zone_id' => $zone->id]);
        $zone->warehouse->update(['is_active' => false]);
        $activeZone = WarehouseZone::factory()->create(['zone_type' => 'finished_goods']);
        WarehouseLocation::factory()->create(['zone_id' => $activeZone->id, 'is_blocked' => true]);
        $usable = WarehouseLocation::factory()->create(['zone_id' => $activeZone->id, 'is_blocked' => false]);
        $this->assertSame($usable->id, app(FinishedGoodInventoryService::class)->location()->id);
    }
}
