<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Jobs\DispatchOutboxMessage;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\ItemService;
use App\Modules\Inventory\Services\StockLocationSummaryService;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\BomItem;
use App\Modules\MRP\Services\BomCostingService;
use App\Modules\MRP\Services\MrpEngineService;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BomInventoryReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_bom_component_cannot_be_retired_or_reidentified(): void
    {
        Uom::create(['code' => 'PCS', 'name' => 'Piece']);
        Uom::create(['code' => 'BOX', 'name' => 'Box']);
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        $bom = Bom::create(['product_id' => Product::factory()->create()->id, 'version' => 1, 'is_active' => true]);
        BomItem::create(['bom_id' => $bom->id, 'item_id' => $item->id, 'quantity_per_unit' => 1, 'unit' => 'PCS', 'waste_factor' => 0]);
        foreach ([['is_active' => false], ['code' => 'CHANGED-CODE'], ['item_type' => 'finished_good'], ['unit_of_measure' => 'BOX']] as $change) {
            try {
                app(ItemService::class)->update($item, $change);
                $this->fail('An active BOM dependency must protect inventory identity and availability.');
            } catch (BusinessRuleException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
        try {
            app(ItemService::class)->delete($item);
            $this->fail('An active BOM component cannot be archived.');
        } catch (BusinessRuleException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertTrue($item->fresh()->is_active);
        $this->assertNull($item->fresh()->deleted_at);
    }

    public function test_product_output_mapping_protects_item_identity_and_active_bom_retirement(): void
    {
        Uom::create(['code' => 'PCS', 'name' => 'Piece']);
        Uom::create(['code' => 'BOX', 'name' => 'Box']);
        $product = Product::factory()->create(['part_number' => 'FG-MAPPED', 'unit_of_measure' => 'PCS']);
        $item = Item::factory()->create(['code' => $product->part_number, 'item_type' => 'finished_good', 'unit_of_measure' => 'PCS']);
        Bom::create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);
        foreach ([['code' => 'FG-NEW'], ['unit_of_measure' => 'BOX'], ['item_type' => 'raw_material'], ['is_active' => false]] as $change) {
            try {
                app(ItemService::class)->update($item, $change);
                $this->fail('The linked finished-good mapping must remain stable.');
            } catch (BusinessRuleException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
        $this->assertSame($product->part_number, $item->fresh()->code);
    }

    public function test_item_stock_readiness_and_filters_exclude_held_and_unusable_bins(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $staff = User::factory()->create(['role_id' => Role::where('slug', 'warehouse_staff')->value('id')]);
        [$item] = $this->stockFixture();
        foreach (["/api/v1/inventory/items/{$item->hash_id}", '/api/v1/inventory/items?stock_status=critical', '/api/v1/inventory/items?below_reorder=1'] as $url) {
            $response = $this->actingAs($staff)->getJson($url)->assertOk();
            $data = str_contains($url, $item->hash_id) ? $response->json('data') : collect($response->json('data'))->firstWhere('code', $item->code);
            $this->assertNotNull($data);
            $this->assertSame('230.000', $data['on_hand_quantity']);
            $this->assertSame('25.000', $data['reserved_quantity']);
            $this->assertSame('80.000', $data['quality_held_quantity']);
            $this->assertSame('100.000', $data['unusable_zone_quantity']);
            $this->assertSame('35.000', $data['usable_quantity']);
            $this->assertSame($data['usable_quantity'], $data['available_quantity']);
            $this->assertSame('critical', $data['stock_status']);
        }
    }

    public function test_mrp_uses_same_physical_usable_supply_before_safety_and_future_supply(): void
    {
        [$item] = $this->stockFixture();
        $method = new \ReflectionMethod(MrpEngineService::class, 'supplyForItem');
        $cache = [];
        $supply = $method->invokeArgs(app(MrpEngineService::class), [$item->id, &$cache]);
        $this->assertSame(35.0, $supply['usable']);
        $this->assertSame(80.0, $supply['quality_held']);
        $this->assertSame(0.0, $supply['available']);
        $this->assertSame($supply, $cache[$item->id]);
    }

    public function test_same_second_item_cost_edit_invalidates_current_bom_only(): void
    {
        Queue::fake([DispatchOutboxMessage::class]);
        $this->travelTo(now()->startOfSecond());
        $item = Item::factory()->create(['standard_cost' => 10]);
        $product = Product::factory()->create();
        $historical = Bom::create(['product_id' => $product->id, 'version' => 1, 'is_active' => false, 'material_cost' => 8, 'total_cost' => 8, 'costed_at' => now()]);
        $bom = Bom::create(['product_id' => $product->id, 'version' => 2, 'is_active' => true]);
        foreach ([$historical, $bom] as $version) {
            BomItem::create(['bom_id' => $version->id, 'item_id' => $item->id, 'quantity_per_unit' => 1, 'unit' => 'pcs', 'waste_factor' => 0]);
        }
        app(BomCostingService::class)->recalculate($bom);
        $order = SalesOrder::factory()->create(['status' => 'confirmed']);
        SalesOrderItem::factory()->create(['sales_order_id' => $order->id, 'product_id' => $product->id]);
        $updated = app(ItemService::class)->update($item, ['standard_cost' => '15.0000']);
        $this->assertSame(1, DB::table('event_outbox')->where('dedupe_key', 'like', 'mrp:replan:item-cost:'.$item->id.':%')->count());
        $message = DB::table('event_outbox')->where('dedupe_key', 'like', 'mrp:replan:item-cost:'.$item->id.':%')->first();
        $payload = json_decode($message->payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([$order->id], $payload['salesOrderIds']);
        $this->assertNull($bom->fresh()->costed_at);
        $this->assertSame('8.00', $historical->fresh()->total_cost);
        $this->assertNotNull($historical->fresh()->costed_at);
        $this->assertSame('15.00', app(BomCostingService::class)->ensureFresh($bom)->total_cost);
        $updated->name = 'Updated name after readiness read';
        $updated->save();
        $this->assertSame('Updated name after readiness read', $item->fresh()->name);
        app(ItemService::class)->update($item, ['standard_cost' => '16.0000']);
        $this->assertSame(2, DB::table('event_outbox')->where('dedupe_key', 'like', 'mrp:replan:item-cost:'.$item->id.':%')->count());
    }

    public function test_late_qc_hold_does_not_consume_free_stock_from_other_bins(): void
    {
        [$item, $location] = $this->stockFixture();
        StockLevel::where('item_id', $item->id)->where('location_id', $location->id)->update(['reserved_quantity' => 30]);
        $readiness = app(StockLocationSummaryService::class)->availability($item->stockLevels()->get());
        $this->assertSame('25.000', $readiness['usable_quantity']);
        $this->assertSame('45.000', $readiness['reserved_quantity']);
    }

    private function stockFixture(): array
    {
        $item = Item::factory()->create(['code' => 'FG-QC-READINESS', 'item_type' => 'finished_good', 'safety_stock' => 40, 'reorder_point' => 50]);
        $regular = WarehouseLocation::factory()->create(['zone_id' => WarehouseZone::factory()->create(['zone_type' => 'finished_goods'])->id]);
        $free = WarehouseLocation::factory()->create();
        $quarantine = WarehouseLocation::factory()->create(['zone_id' => WarehouseZone::factory()->create(['zone_type' => 'quarantine'])->id]);
        $scrap = WarehouseLocation::factory()->create(['zone_id' => WarehouseZone::factory()->create(['zone_type' => 'scrap'])->id]);
        $blocked = WarehouseLocation::factory()->create(['is_active' => false]);
        foreach ([[$regular, 100, 10], [$free, 30, 5], [$quarantine, 60, 10], [$scrap, 20, 0], [$blocked, 20, 0]] as [$location, $quantity, $reserved]) {
            StockLevel::create(['item_id' => $item->id, 'location_id' => $location->id, 'quantity' => $quantity, 'reserved_quantity' => $reserved, 'weighted_avg_cost' => 5]);
        }
        $order = WorkOrder::factory()->create();
        $output = WorkOrderOutput::create(['work_order_id' => $order->id, 'recorded_by' => $order->created_by, 'recorded_at' => now(), 'good_count' => 80, 'reject_count' => 0, 'batch_code' => 'PENDING-QC-LOT']);
        StockMovement::create(['movement_type' => 'production_receipt', 'item_id' => $item->id, 'quantity' => 80, 'unit_cost' => 5, 'total_cost' => 400, 'to_location_id' => $regular->id, 'reference_type' => 'work_order_output', 'reference_id' => $output->id, 'created_by' => $order->created_by, 'lot_number' => $output->batch_code]);
        return [$item, $regular];
    }
}
