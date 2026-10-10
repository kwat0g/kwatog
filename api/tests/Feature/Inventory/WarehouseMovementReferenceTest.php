<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Production\Resources\WorkOrderResource;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryItem;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarehouseMovementReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_and_delivery_movements_show_document_numbers_and_links(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'warehouse_staff')->value('id')]);
        $item = Item::factory()->create();
        $grn = GoodsReceiptNote::factory()->create(['grn_number' => 'GRN-202610-TEST']);
        $order = SalesOrder::factory()->create();
        $delivery = Delivery::create(['delivery_number' => 'DEL-202610-TEST',
            'sales_order_id' => $order->id, 'scheduled_date' => today(), 'created_by' => $actor->id]);
        $deliveryItem = DeliveryItem::create(['delivery_id' => $delivery->id,
            'sales_order_item_id' => SalesOrderItem::factory()->create(['sales_order_id' => $order->id])->id,
            'quantity' => 2, 'unit_price' => 1]);

        foreach ([
            ['grn_receipt', 'goods_receipt_note', $grn->id, 'GRN-202610-TEST', '/inventory/grn/'.$grn->hash_id],
            ['delivery', 'delivery_item', $deliveryItem->id, 'DEL-202610-TEST', '/supply-chain/deliveries/'.$delivery->hash_id],
        ] as [$type, $referenceType, $referenceId, $label, $url]) {
            $movement = StockMovement::create(['item_id' => $item->id, 'movement_type' => $type,
                'quantity' => 2, 'unit_cost' => 1, 'total_cost' => 2,
                'reference_type' => $referenceType, 'reference_id' => $referenceId,
                'created_by' => $actor->id, 'created_at' => now()]);
            $this->actingAs($actor)->getJson('/api/v1/inventory/stock-movements?movement_id='.$movement->hash_id)
                ->assertOk()->assertJsonPath('data.0.reference_label', $label)
                ->assertJsonPath('data.0.reference_url', $url);
        }
    }

    public function test_movements_show_work_order_numbers_and_output_parent_links(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'warehouse_staff')->value('id')]);
        $wo = WorkOrder::factory()->create(['wo_number' => 'WO-202610-TEST']);
        $output = WorkOrderOutput::create(['work_order_id' => $wo->id, 'shift' => 'day', 'recorded_at' => now(),
            'good_count' => 1, 'reject_count' => 0, 'recorded_by' => $actor->id]);
        foreach (['work_order' => $wo->id, 'work_order_output' => $output->id] as $type => $id) {
            $movement = StockMovement::create(['item_id' => Item::factory()->create()->id,
                'movement_type' => 'production_receipt', 'quantity' => 1, 'unit_cost' => 1, 'total_cost' => 1,
                'reference_type' => $type, 'reference_id' => $id, 'created_by' => $actor->id, 'created_at' => now()]);
            $row = $this->actingAs($actor)->getJson('/api/v1/inventory/stock-movements?movement_id='.$movement->hash_id)
                ->assertOk()->json('data.0');
            $this->assertSame('WO-202610-TEST', $row['reference_label']);
            $this->assertSame($wo->hash_id, $row['reference_work_order_id']);
            $this->assertSame('/production/work-orders/'.$wo->hash_id, $row['reference_url']);
        }
        StockMovement::create(['item_id' => Item::factory()->create()->id,
            'movement_type' => 'opening', 'quantity' => 1, 'unit_cost' => 1, 'total_cost' => 1,
            'created_by' => $actor->id, 'created_at' => now()]);
        $this->getJson('/api/v1/inventory/stock-movements?search=WO-202610-TEST')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/inventory/stock-movements?work_order_id='.$wo->hash_id)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/inventory/stock-movements?search=WO-DOES-NOT-EXIST')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_manual_issue_and_source_return_keep_document_lineage_in_search_and_filter(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'warehouse_staff')->value('id')]);
        $wo = WorkOrder::factory()->create(['wo_number' => 'WO-MANUAL-LINEAGE']);
        $other = WorkOrder::factory()->create();
        $slip = MaterialIssueSlip::create([
            'slip_number' => 'MIS-MANUAL-LINEAGE', 'work_order_id' => $wo->id,
            'issued_date' => today(), 'issued_by' => $actor->id, 'created_by' => $actor->id,
            'status' => 'issued', 'total_value' => '10.00',
        ]);
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();
        $wo->materials()->create(['item_id' => $item->id, 'bom_quantity' => 10, 'standard_cost' => 10]);
        $source = StockMovement::create(['item_id' => $item->id, 'movement_type' => 'material_issue',
            'from_location_id' => $location->id,
            'quantity' => 10, 'unit_cost' => 1, 'total_cost' => 10, 'reference_type' => 'material_issue_slip',
            'reference_id' => $slip->id, 'created_by' => $actor->id, 'created_at' => now()]);
        $return = StockMovement::create(['item_id' => $item->id, 'movement_type' => 'material_return',
            'quantity' => 2, 'unit_cost' => 1, 'total_cost' => 2, 'reference_type' => 'stock_movement',
            'reference_id' => $source->id, 'created_by' => $actor->id, 'created_at' => now()]);
        $this->actingAs($actor)->getJson('/api/v1/inventory/stock-movements?work_order_id='.$wo->hash_id)
            ->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/inventory/stock-movements?search=WO-MANUAL-LINEAGE')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/inventory/stock-movements?search=MIS-MANUAL-LINEAGE')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/inventory/stock-movements?work_order_id='.$other->hash_id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/inventory/stock-movements?movement_id='.$return->hash_id)
            ->assertOk()->assertJsonPath('data.0.reference_label', 'Unused return · WO-MANUAL-LINEAGE');
    }

    public function test_fully_returned_issue_no_longer_prompts_for_handover(): void
    {
        $actor = User::factory()->create();
        $wo = WorkOrder::factory()->create();
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();
        $wo->materials()->create(['item_id' => $item->id, 'bom_quantity' => 10, 'standard_cost' => 10]);
        $source = StockMovement::create(['item_id' => $item->id, 'movement_type' => 'material_issue',
            'from_location_id' => $location->id,
            'quantity' => 10, 'unit_cost' => 1, 'total_cost' => 10, 'reference_type' => 'work_order',
            'reference_id' => $wo->id, 'created_by' => $actor->id, 'created_at' => now()]);
        $this->assertSame(1, (new WorkOrderResource($wo))->resolve()['warehouse_handover_pending']);
        $return = StockMovement::create(['item_id' => $item->id, 'movement_type' => 'material_return',
            'quantity' => 2, 'unit_cost' => 1, 'total_cost' => 2, 'reference_type' => 'stock_movement',
            'reference_id' => $source->id, 'created_by' => $actor->id, 'created_at' => now()]);
        $this->assertSame(1, (new WorkOrderResource($wo))->resolve()['warehouse_handover_pending']);
        $return->update(['quantity' => 10, 'total_cost' => 10]);
        $this->assertSame(0, (new WorkOrderResource($wo))->resolve()['warehouse_handover_pending']);
    }

    public function test_stock_search_filters_items_and_invalid_filters_do_not_expand_results(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'warehouse_staff')->value('id')]);
        $item = Item::factory()->create(['code' => 'RM-FIND', 'name' => 'Searchable resin']);
        $other = Item::factory()->create(['code' => 'RM-OTHER']);
        foreach ([$item, $other] as $record) {
            StockLevel::factory()->create(['item_id' => $record->id]);
        }
        $this->actingAs($actor)->getJson('/api/v1/inventory/stock-levels?search=Searchable')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/inventory/stock-movements?per_page=0')->assertUnprocessable();
        $this->getJson('/api/v1/inventory/stock-levels?item_id=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/inventory/items/'.$item->hash_id.'/stock-card?location_id=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/inventory/stock-movements?to='.now()->toDateString())->assertOk();
    }

    public function test_stock_levels_read_production_lots_without_releasing_missing_qc_evidence(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $actor = User::factory()->create(['role_id' => Role::where('slug', 'warehouse_staff')->value('id')]);
        $item = Item::factory()->create();
        $level = StockLevel::factory()->create(['item_id' => $item->id, 'quantity' => '10.000', 'reserved_quantity' => '0.000']);
        $wo = WorkOrder::factory()->create();
        $output = WorkOrderOutput::create(['work_order_id' => $wo->id, 'shift' => 'day', 'recorded_at' => now(),
            'good_count' => 10, 'reject_count' => 0, 'recorded_by' => $actor->id]);
        StockMovement::create(['item_id' => $item->id, 'movement_type' => 'production_receipt',
            'quantity' => 10, 'unit_cost' => 1, 'total_cost' => 10, 'to_location_id' => $level->location_id,
            'lot_number' => 'STOCK-LEVELS-QC-PENDING', 'reference_type' => 'work_order_output',
            'reference_id' => $output->id, 'created_by' => $actor->id]);

        $this->actingAs($actor)->getJson('/api/v1/inventory/stock-levels?item_id='.$item->hash_id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.quantity', '10.000')
            ->assertJsonPath('data.0.quality_held_quantity', '10.000')->assertJsonPath('data.0.usable_quantity', '0.000');
        $this->assertDatabaseCount('stock_movements', 1);
    }
}
