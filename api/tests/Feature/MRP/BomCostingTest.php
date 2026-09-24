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
use App\Modules\Production\Models\ProductRouting;
use App\Modules\Production\Models\RoutingOperation;
use App\Modules\Production\Services\ProductionRoutingService;
use Illuminate\Database\QueryException;
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

    public function test_inactive_manufactured_components_are_material_leaves_for_both_paths(): void
    {
        $finishedGood = Product::factory()->create();
        $subassembly = Product::factory()->create([
            'part_number' => 'SA-INACTIVE-'.strtoupper(substr(uniqid(), -5)),
        ]);
        $subassemblyItem = Item::factory()->create([
            'code' => $subassembly->part_number,
            'standard_cost' => '99.0000',
            'unit_of_measure' => 'pcs',
        ]);
        $rawMaterial = Item::factory()->create([
            'standard_cost' => '3.0000',
            'unit_of_measure' => 'pcs',
        ]);

        $this->service->create($subassembly->id, [[
            'item_id' => $rawMaterial->id,
            'quantity_per_unit' => '2.0000',
            'unit' => 'pcs',
        ]]);
        $bom = $this->service->create($finishedGood->id, [[
            'item_id' => $subassemblyItem->id,
            'quantity_per_unit' => '2.0000',
            'unit' => 'pcs',
        ]]);

        $subassembly->update(['is_active' => false]);

        $exploded = $this->service->explode($finishedGood->id, 1.0);
        $recosted = $this->service->ensureFreshForPlanning($bom);

        $this->assertSame($subassembly->part_number, $exploded->first()['item_code']);
        $this->assertSame('2.000', $exploded->first()['gross_quantity']);
        $this->assertSame('standard_cost', $recosted->items->first()->cost_source);
        $this->assertSame('99.0000', (string) $recosted->items->first()->unit_cost);
        $this->assertSame('198.00', (string) $recosted->material_cost);
    }

    public function test_bom_authoring_rejects_a_transitive_cycle_before_costing(): void
    {
        $productA = Product::factory()->create([
            'part_number' => 'CYCLE-A-'.strtoupper(substr(uniqid(), -5)),
        ]);
        $productB = Product::factory()->create([
            'part_number' => 'CYCLE-B-'.strtoupper(substr(uniqid(), -5)),
        ]);
        $itemA = Item::factory()->create([
            'code' => $productA->part_number,
            'unit_of_measure' => 'pcs',
        ]);
        $itemB = Item::factory()->create([
            'code' => $productB->part_number,
            'unit_of_measure' => 'pcs',
        ]);

        // B -> A is valid while A has no BOM. Adding A -> B would close the cycle.
        $this->service->create($productB->id, [[
            'item_id' => $itemA->id,
            'quantity_per_unit' => '1.0000',
            'unit' => 'pcs',
        ]]);

        $this->expectExceptionMessage('Circular bill of materials detected in the BOM definition');
        $this->service->create($productA->id, [[
            'item_id' => $itemB->id,
            'quantity_per_unit' => '1.0000',
            'unit' => 'pcs',
        ]]);
    }

    public function test_database_rejects_duplicate_components_outside_the_service(): void
    {
        $product = Product::factory()->create();
        $material = Item::factory()->create();
        $bom = Bom::create([
            'product_id' => $product->id,
            'version' => 1,
            'is_active' => true,
        ]);

        BomItem::create([
            'bom_id' => $bom->id,
            'item_id' => $material->id,
            'quantity_per_unit' => '1.0000',
            'unit' => 'pcs',
            'waste_factor' => '0.00',
        ]);

        $this->expectException(QueryException::class);
        BomItem::create([
            'bom_id' => $bom->id,
            'item_id' => $material->id,
            'quantity_per_unit' => '2.0000',
            'unit' => 'pcs',
            'waste_factor' => '0.00',
        ]);
    }

    public function test_bom_item_mutations_have_timestamps_and_audit_rows(): void
    {
        $product = Product::factory()->create();
        $material = Item::factory()->create();
        $bom = Bom::create([
            'product_id' => $product->id,
            'version' => 1,
            'is_active' => true,
        ]);
        $line = BomItem::create([
            'bom_id' => $bom->id,
            'item_id' => $material->id,
            'quantity_per_unit' => '1.0000',
            'unit' => 'pcs',
            'waste_factor' => '0.00',
        ]);

        $this->assertNotNull($line->created_at);
        $this->assertNotNull($line->updated_at);

        $line->update(['quantity_per_unit' => '1.2500']);

        $this->assertDatabaseHas('audit_logs', [
            'model_type' => BomItem::class,
            'model_id' => $line->id,
            'action' => 'updated',
        ]);
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

    public function test_bom_costing_rolls_up_active_subassembly_costs_and_tracks_total(): void
    {
        $finishedGood = Product::factory()->create();
        $subassembly = Product::factory()->create([
            'part_number' => 'SA-'.strtoupper(substr(uniqid(), -6)),
        ]);
        $subassemblyItem = Item::factory()->create([
            'code' => $subassembly->part_number,
            'standard_cost' => '99.0000',
            'unit_of_measure' => 'pcs',
        ]);
        $rawMaterial = Item::factory()->create([
            'standard_cost' => '3.0000',
            'unit_of_measure' => 'pcs',
        ]);

        $this->service->create($subassembly->id, [[
            'item_id' => $rawMaterial->id,
            'quantity_per_unit' => '2.0000',
            'unit' => 'pcs',
            'waste_factor' => '0.00',
        ]]);

        $bom = $this->service->create($finishedGood->id, [[
            'item_id' => $subassemblyItem->id,
            'quantity_per_unit' => '2.0000',
            'unit' => 'pcs',
            'waste_factor' => '0.00',
        ]]);

        $line = $bom->items->first();

        $this->assertSame('6.0000', (string) $line->unit_cost);
        $this->assertSame('bom_rollup', $line->cost_source);
        $this->assertSame('12.00', (string) $line->extended_cost);
        $this->assertSame('12.00', (string) $bom->material_cost);
        $this->assertSame('12.00', (string) $bom->total_cost);
    }

    public function test_bom_costing_includes_active_routing_labor_machine_and_overhead_costs(): void
    {
        $product = Product::factory()->create();
        $material = Item::factory()->create([
            'standard_cost' => '10.0000',
            'unit_of_measure' => 'pcs',
        ]);
        $routing = ProductRouting::create([
            'product_id' => $product->id,
            'version' => 1,
            'is_active' => true,
            'total_cycle_time' => '30.00',
        ]);
        RoutingOperation::create([
            'routing_id' => $routing->id,
            'sequence' => 10,
            'operation_name' => 'Assembly',
            'cycle_time_minutes' => '30.00',
            'labor_rate_per_hour' => '10.0000',
            'machine_rate_per_hour' => '20.0000',
            'overhead_rate_per_hour' => '5.0000',
        ]);

        $bom = $this->service->create($product->id, [[
            'item_id' => $material->id,
            'quantity_per_unit' => '1.0000',
            'unit' => 'pcs',
            'waste_factor' => '0.00',
        ]]);

        $this->assertSame('10.00', (string) $bom->material_cost);
        $this->assertSame('5.00', (string) $bom->labor_cost);
        $this->assertSame('10.00', (string) $bom->machine_cost);
        $this->assertSame('2.50', (string) $bom->overhead_cost);
        $this->assertSame('27.50', (string) $bom->total_cost);
        $this->assertSame('standard_cost+routing', $bom->cost_basis);
    }

    public function test_setup_cost_is_allocated_across_the_bom_cost_batch_size(): void
    {
        $product = Product::factory()->create();
        $material = Item::factory()->create([
            'standard_cost' => '10.0000',
            'unit_of_measure' => 'pcs',
        ]);
        $routing = ProductRouting::create([
            'product_id' => $product->id,
            'version' => 1,
            'is_active' => true,
            'total_cycle_time' => '30.00',
        ]);
        RoutingOperation::create([
            'routing_id' => $routing->id,
            'sequence' => 10,
            'operation_name' => 'Assembly',
            'setup_time_minutes' => '60.00',
            'cycle_time_minutes' => '30.00',
            'labor_rate_per_hour' => '10.0000',
            'machine_rate_per_hour' => '20.0000',
            'overhead_rate_per_hour' => '5.0000',
        ]);

        $bom = $this->service->create($product->id, [[
            'item_id' => $material->id,
            'quantity_per_unit' => '1.0000',
            'unit' => 'pcs',
            'waste_factor' => '0.00',
        ]], '10');

        $this->assertSame('10.000', (string) $bom->cost_batch_size);
        $this->assertSame('6.00', (string) $bom->labor_cost);
        $this->assertSame('12.00', (string) $bom->machine_cost);
        $this->assertSame('3.00', (string) $bom->overhead_cost);
        $this->assertSame('31.00', (string) $bom->total_cost);
    }

    public function test_routing_changes_recalculate_the_active_bom_snapshot(): void
    {
        $product = Product::factory()->create();
        $material = Item::factory()->create([
            'standard_cost' => '10.0000',
            'unit_of_measure' => 'pcs',
        ]);
        $bom = $this->service->create($product->id, [[
            'item_id' => $material->id,
            'quantity_per_unit' => '1.0000',
            'unit' => 'pcs',
            'waste_factor' => '0.00',
        ]]);

        app(ProductionRoutingService::class)->create([
            'product_id' => $product->id,
            'notes' => null,
            'operations' => [[
                'sequence' => 10,
                'operation_name' => 'Assembly',
                'cycle_time_minutes' => '30.00',
                'labor_rate_per_hour' => '10.0000',
                'machine_rate_per_hour' => '20.0000',
                'overhead_rate_per_hour' => '5.0000',
            ]],
        ]);

        $this->assertSame('27.50', (string) $bom->fresh()->total_cost);
    }

    public function test_new_bom_version_uses_highest_version_including_trashed_rows(): void
    {
        $product = Product::factory()->create();
        $material = Item::factory()->create();
        $row = [[
            'item_id' => $material->id,
            'quantity_per_unit' => '1.0000',
            'unit' => 'pcs',
        ]];

        $this->service->create($product->id, $row);
        $versionTwo = $this->service->create($product->id, $row);
        $versionTwo->forceFill(['is_active' => false])->save();
        $this->service->delete($versionTwo);

        $newVersion = $this->service->create($product->id, $row);

        $this->assertSame(3, $newVersion->version);
    }

    public function test_bom_service_rejects_scientific_notation_quantity_input(): void
    {
        $product = Product::factory()->create();
        $material = Item::factory()->create();

        $this->expectException(BusinessRuleException::class);
        $this->service->create($product->id, [[
            'item_id' => $material->id,
            'quantity_per_unit' => '1e3',
            'unit' => 'pcs',
        ]]);
    }
}
