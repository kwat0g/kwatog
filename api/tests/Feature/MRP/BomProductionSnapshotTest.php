<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Services\ProductService;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Uom;
use App\Modules\MRP\Resources\BomResource;
use App\Modules\MRP\Services\BomService;
use App\Modules\Production\Services\WorkOrderService;
use Database\Seeders\ManufacturedProductInventorySeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BomProductionSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_work_order_freezes_bom_version_and_product_valuation_cost(): void
    {
        $this->seed(SettingsSeeder::class);
        $product = Product::factory()->create(['standard_cost' => '20.00', 'base_selling_price' => '30.00']);
        $material = Item::factory()->create(['standard_cost' => '3.0000']);
        $rows = [['item_id' => $material->id, 'quantity_per_unit' => '2.0000', 'unit' => 'pcs']];
        $bom = app(BomService::class)->create($product->id, $rows);
        $wo = app(WorkOrderService::class)->createDraft([
            'product_id' => $product->id, 'quantity_target' => 10,
            'planned_start' => now(), 'planned_end' => now()->addDay(), 'created_by' => User::factory()->create()->id,
        ]);
        $this->assertSame($bom->id, $wo->bom_id);
        $this->assertSame(1, $wo->bom_version);
        $this->assertSame('20.0000', $wo->unit_standard_cost);
        $product->update(['standard_cost' => '40.00']);
        app(BomService::class)->create($product->id, $rows);
        $this->assertSame('20.0000', $wo->fresh()->unit_standard_cost);
        $this->assertSame(1, $wo->fresh()->bom_version);
        $resource = (new BomResource(app(BomService::class)->show($bom->fresh())))->resolve();
        $this->assertSame('30.00', $resource['product']['base_selling_price']);
        $this->assertSame('materials_only', $resource['cost_completeness']);
        $this->assertSame('24.00', $resource['estimated_margin']['profit_per_unit']);
        $this->assertSame('80.00', $resource['estimated_margin']['margin_percent']);
        $this->assertSame('Material contribution estimate', $resource['estimated_margin']['label']);
    }

    public function test_manufactured_inventory_setup_is_idempotent_and_creates_no_stock(): void
    {
        Uom::create(['code' => 'PCS', 'name' => 'Pieces']);
        $product = Product::factory()->create(['unit_of_measure' => 'PCS', 'standard_cost' => '20.00']);
        $material = Item::factory()->create(['unit_of_measure' => 'PCS', 'standard_cost' => '3.0000']);
        app(BomService::class)->create($product->id, [['item_id' => $material->id, 'quantity_per_unit' => '1', 'unit' => 'PCS']]);
        $this->seed(ManufacturedProductInventorySeeder::class);
        $this->seed(ManufacturedProductInventorySeeder::class);
        $item = Item::where('code', $product->part_number)->sole();
        $this->assertSame('finished_good', $item->item_type->value);
        $this->assertSame('20.0000', $item->standard_cost);
        $this->assertSame(0, $item->stockLevels()->count());
        $this->assertSame(0, $item->movements()->count());
        $ready = app(ProductService::class)->show($product);
        $this->assertTrue($ready->finished_good_readiness['ready']);
    }

    public function test_product_identity_is_locked_once_inventory_counterpart_exists(): void
    {
        Uom::create(['code' => 'PCS', 'name' => 'Pieces']);
        $product = Product::factory()->create(['unit_of_measure' => 'PCS']);
        Item::factory()->create(['code' => $product->part_number, 'item_type' => 'finished_good', 'unit_of_measure' => 'PCS']);
        $this->expectException(BusinessRuleException::class);
        app(ProductService::class)->update($product, ['part_number' => 'CHANGED-CODE']);
    }
}
