<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemUomConversion;
use App\Modules\Inventory\Models\Uom;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\BomItem;
use App\Modules\MRP\Services\BomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BomCostingTest extends TestCase
{
    use RefreshDatabase;

    private BomService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BomService::class);
    }

    public function test_bom_creation_snapshots_standard_cost_per_line_and_header(): void
    {
        $product = Product::factory()->create();
        $material = Item::factory()->create([
            'standard_cost' => '12.3456',
            'unit_of_measure' => 'pcs',
        ]);

        $bom = $this->service->create($product->id, [[
            'item_id' => $material->id,
            'quantity_per_unit' => '2.5000',
            'unit' => 'pcs',
            'waste_factor' => '10.00',
        ]]);

        $line = $bom->items->first();

        $this->assertSame('12.3456', (string) $line->unit_cost);
        $this->assertSame('33.95', (string) $line->extended_cost);
        $this->assertSame('33.95', (string) $bom->material_cost);
        $this->assertSame('standard_cost', $bom->cost_basis);
        $this->assertNotNull($bom->costed_at);
        $this->assertSame([], $bom->cost_warnings);
    }

    public function test_bom_costing_converts_alternate_uom_before_extending_cost(): void
    {
        $product = Product::factory()->create();
        $material = Item::factory()->create([
            'standard_cost' => '4.0000',
            'unit_of_measure' => 'KG',
        ]);
        $kg = Uom::create(['code' => 'KG', 'name' => 'Kilogram']);
        $bag = Uom::create(['code' => 'BAG', 'name' => 'Bag']);
        ItemUomConversion::create([
            'item_id' => $material->id,
            'from_uom_id' => $bag->id,
            'to_uom_id' => $kg->id,
            'factor' => '25.000000',
        ]);

        $bom = $this->service->create($product->id, [[
            'item_id' => $material->id,
            'quantity_per_unit' => '2.0000',
            'unit' => 'BAG',
            'waste_factor' => '10.00',
        ]]);

        $this->assertSame('220.00', (string) $bom->material_cost);
        $this->assertSame('220.00', (string) $bom->items->first()->extended_cost);
    }

    public function test_bom_rejects_duplicate_components(): void
    {
        $product = Product::factory()->create();
        $material = Item::factory()->create();
        $row = [
            'item_id' => $material->id,
            'quantity_per_unit' => '1.0000',
            'unit' => 'pcs',
            'waste_factor' => '0.00',
        ];

        $this->expectException(BusinessRuleException::class);
        $this->service->create($product->id, [$row, $row]);
    }

    public function test_bom_rejects_missing_alternate_uom_conversion(): void
    {
        $product = Product::factory()->create();
        $material = Item::factory()->create(['unit_of_measure' => 'KG']);
        Uom::create(['code' => 'BAG', 'name' => 'Bag']);

        $this->expectException(BusinessRuleException::class);
        $this->service->create($product->id, [[
            'item_id' => $material->id,
            'quantity_per_unit' => '1.0000',
            'unit' => 'BAG',
            'waste_factor' => '0.00',
        ]]);
    }

    public function test_existing_bom_can_be_recosted_after_item_cost_changes(): void
    {
        $product = Product::factory()->create();
        $material = Item::factory()->create(['standard_cost' => '10.0000']);
        $bom = Bom::create([
            'product_id' => $product->id,
            'version' => 1,
            'is_active' => true,
        ]);
        BomItem::create([
            'bom_id' => $bom->id,
            'item_id' => $material->id,
            'quantity_per_unit' => '2.0000',
            'unit' => 'pcs',
            'waste_factor' => '0.00',
            'sort_order' => 0,
        ]);

        $material->update(['standard_cost' => '11.2500']);
        $recosted = $this->service->recalculate($bom);

        $this->assertSame('22.50', (string) $recosted->material_cost);
        $this->assertSame('11.2500', (string) $recosted->items->first()->unit_cost);
    }
}
