<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemUomConversion;
use App\Modules\Inventory\Models\Uom;
use App\Modules\MRP\Events\MrpReplanRequested;
use App\Modules\MRP\Services\BomCostingService;
use App\Modules\MRP\Services\BomService;
use App\Modules\Production\Models\ProductRouting;
use App\Modules\Production\Models\RoutingOperation;
use App\Modules\Production\Services\ProductionRoutingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BomInventoryCostSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversion_edit_refreshes_nested_costs_within_the_same_second(): void
    {
        $this->freezeTime();
        [$admin, $material, $kg, $bag] = $this->conversionFixture();
        $subassembly = Product::factory()->create();
        $subassemblyItem = Item::factory()->create([
            'code' => $subassembly->part_number,
            'item_type' => 'finished_good',
        ]);
        $service = app(BomService::class);
        $subBom = $service->create($subassembly->id, [$this->line($material, '2', 'BAG', '10')]);
        $parent = $service->create(Product::factory()->create()->id, [$this->line($subassemblyItem, '2')]);
        $this->assertSame('440.00', (string) $parent->total_cost);

        $this->actingAs($admin)->postJson("/api/v1/inventory/items/{$material->hash_id}/uom-conversions", [
            'from_uom_id' => $bag->hash_id, 'to_uom_id' => $kg->hash_id, 'factor' => '50',
        ])->assertCreated();

        $this->assertSame('440.00', (string) $subBom->fresh()->total_cost);
        $this->assertNotNull($subBom->fresh()->costed_at);
        $recosted = $service->ensureFreshForPlanning($parent);
        $this->assertSame('880.00', (string) $recosted->total_cost);
    }

    public function test_conversion_delete_invalidates_snapshot_and_prevents_stale_planning_cost(): void
    {
        [$admin, $material, , , $conversion] = $this->conversionFixture();
        $service = app(BomService::class);
        $bom = $service->create(Product::factory()->create()->id, [$this->line($material, '2', 'BAG')]);

        $this->actingAs($admin)->deleteJson("/api/v1/inventory/items/{$material->hash_id}/uom-conversions/{$conversion->hash_id}")
            ->assertNoContent();
        $this->assertNull($bom->fresh()->costed_at);
        $this->expectException(BusinessRuleException::class);
        $service->ensureFreshForPlanning($bom);
    }

    public function test_historical_snapshot_cannot_be_recosted_or_invalidated(): void
    {
        $service = app(BomService::class);
        $product = Product::factory()->create();
        $material = Item::factory()->create(['standard_cost' => '10']);
        $old = $service->create($product->id, [$this->line($material)]);
        $service->create($product->id, [$this->line($material, '2')]);
        $material->update(['standard_cost' => '99']);
        app(BomCostingService::class)->invalidateForItems([(int) $material->id]);

        try {
            $service->recalculate($old);
            $this->fail('Historical cost must remain frozen.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('historical', strtolower($e->getMessage()));
        }
        $this->assertSame('10.00', (string) $old->fresh()->total_cost);
        $this->assertNotNull($old->fresh()->costed_at);
    }

    public function test_manufactured_code_collision_rejects_wrong_item_type(): void
    {
        $this->assertInvalidManufacturedMapping('raw_material', 'pcs');
    }

    public function test_restoring_legacy_active_bom_refuses_retired_components(): void
    {
        $service = app(BomService::class);
        $material = Item::factory()->create(['standard_cost' => '10']);
        $bom = $service->create(Product::factory()->create()->id, [$this->line($material)]);
        // Simulate a legacy archived active version, outside current delete guards.
        $bom->delete();
        $material->update(['is_active' => false]);
        $this->expectException(BusinessRuleException::class);
        $service->restore($bom);
    }

    public function test_manufactured_mapping_rejects_unequal_base_units(): void
    {
        $this->assertInvalidManufacturedMapping('finished_good', 'KG');
    }

    public function test_cost_warnings_distinguish_missing_routing_and_missing_operation_rates(): void
    {
        $service = app(BomService::class);
        $product = Product::factory()->create();
        $material = Item::factory()->create(['standard_cost' => '10']);
        $bom = $service->create($product->id, [$this->line($material)]);
        $this->assertContains('missing_routing', array_column($bom->cost_warnings, 'type'));

        $routing = ProductRouting::create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);
        RoutingOperation::create([
            'routing_id' => $routing->id, 'sequence' => 10, 'operation_name' => 'Assembly',
            'cycle_time_minutes' => '30', 'labor_rate_per_hour' => '0',
            'machine_rate_per_hour' => '0', 'overhead_rate_per_hour' => '0',
        ]);
        $recosted = $service->recalculate($bom);
        $this->assertContains('missing_operation_rates', array_column($recosted->cost_warnings, 'type'));
    }

    public function test_repeated_routing_activation_within_one_second_keeps_each_replan(): void
    {
        $this->freezeTime();
        $product = Product::factory()->create();
        $order = SalesOrder::factory()->create(['status' => 'confirmed']);
        SalesOrderItem::factory()->create(['sales_order_id' => $order->id, 'product_id' => $product->id]);
        $service = app(ProductionRoutingService::class);
        $payload = ['product_id' => $product->id, 'operations' => [[
            'sequence' => 10, 'operation_name' => 'Assembly', 'cycle_time_minutes' => '30',
        ]]];
        $first = $service->create($payload);
        $second = $service->create($payload);
        $service->activate($first);
        $service->activate($second);
        $service->activate($first);

        $this->assertSame(2, DB::table('event_outbox')
            ->where('event_type', MrpReplanRequested::class)
            ->where('dedupe_key', 'like', "mrp:replan:routing:{$first->id}:routing_activated:%")
            ->count());
    }

    private function assertInvalidManufacturedMapping(string $type, string $unit): void
    {
        $service = app(BomService::class);
        $subassembly = Product::factory()->create();
        $raw = Item::factory()->create(['standard_cost' => '3']);
        $service->create($subassembly->id, [$this->line($raw)]);
        $component = Item::factory()->create([
            'code' => $subassembly->part_number, 'item_type' => $type, 'unit_of_measure' => $unit,
        ]);
        $this->expectException(BusinessRuleException::class);
        $service->create(Product::factory()->create()->id, [$this->line($component, '1', $unit)]);
    }

    private function line(Item $item, string $quantity = '1', string $unit = 'pcs', string $waste = '0'): array
    {
        return ['item_id' => $item->id, 'quantity_per_unit' => $quantity, 'unit' => $unit, 'waste_factor' => $waste];
    }

    private function conversionFixture(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(['role_id' => Role::where('slug', 'system_admin')->value('id')]);
        $kg = Uom::create(['code' => 'KG', 'name' => 'Kilogram']);
        $bag = Uom::create(['code' => 'BAG', 'name' => 'Bag']);
        $item = Item::factory()->create(['unit_of_measure' => 'KG', 'standard_cost' => '4']);
        $conversion = ItemUomConversion::create([
            'item_id' => $item->id, 'from_uom_id' => $bag->id, 'to_uom_id' => $kg->id, 'factor' => '25',
        ]);

        return [$admin, $item, $kg, $bag, $conversion];
    }
}
