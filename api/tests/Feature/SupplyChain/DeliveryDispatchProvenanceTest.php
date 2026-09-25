<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Models\Inspection;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Services\DeliveryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryDispatchProvenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_uses_the_inspected_output_stock_instead_of_an_empty_first_bin(): void
    {
        [$delivery, $receipt, $item, $emptyLocation] = $this->arrange(secondBin: true);

        $preparation = app(DeliveryService::class)->show($delivery)->preparation->first();
        $this->assertSame('0.000', $preparation['shortage']);
        $this->assertSame('APPROVED-BATCH', $preparation['lot_number']);
        app(DeliveryService::class)->updateStatus($delivery, DeliveryStatus::InTransit);

        $movement = $delivery->items()->firstOrFail()->stockMovement;
        $this->assertSame($receipt->to_location_id, $movement->from_location_id);
        $this->assertSame('APPROVED-BATCH', $movement->lot_number);
        $this->assertSame('7.000', (string) StockLevel::query()
            ->where('item_id', $item->id)->where('location_id', $receipt->to_location_id)->value('quantity'));
        $this->assertSame('0.000', (string) StockLevel::query()
            ->where('item_id', $item->id)->where('location_id', $emptyLocation->id)->value('quantity'));
    }

    public function test_dispatch_cannot_substitute_an_older_unapproved_lot_of_the_same_item(): void
    {
        [$delivery, $receipt, $item] = $this->arrange(olderLot: true);

        app(DeliveryService::class)->updateStatus($delivery, DeliveryStatus::InTransit);

        $movement = $delivery->items()->firstOrFail()->stockMovement;
        $this->assertSame('APPROVED-BATCH', $movement->lot_number);
        $this->assertSame($receipt->to_location_id, $movement->from_location_id);
        $this->assertSame('12.000', (string) StockLevel::query()
            ->where('item_id', $item->id)->where('location_id', $receipt->to_location_id)->value('quantity'));
    }

    public function test_an_in_transit_shipment_cannot_release_its_allocation_by_plain_cancellation(): void
    {
        [$delivery] = $this->arrange();
        $service = app(DeliveryService::class);
        $service->updateStatus($delivery, DeliveryStatus::InTransit);
        $movementId = $delivery->items()->firstOrFail()->stock_movement_id;

        try {
            $service->updateStatus($delivery->fresh(), DeliveryStatus::Cancelled);
            $this->fail('Dispatched stock needs a physical recovery/return, not a released delivery allocation.');
        } catch (BusinessRuleException $error) {
            $this->assertMatchesRegularExpression('/return|dispatch|transit/i', $error->getMessage());
        }

        $this->assertSame(DeliveryStatus::InTransit, $delivery->fresh()->status);
        $this->assertSame($movementId, $delivery->items()->firstOrFail()->stock_movement_id);
    }

    public function test_dispatch_role_can_load_delivery_form_sources_without_broad_crm_access(): void
    {
        $this->seed(RolePermissionSeeder::class);
        [$delivery] = $this->arrange();
        $officer = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'impex_officer')->value('id'),
        ]);

        $this->actingAs($officer, 'sanctum')->getJson('/api/v1/crm/sales-orders')->assertForbidden();
        $this->getJson('/api/v1/supply-chain/deliveries/form-options')
            ->assertOk()
            ->assertJsonFragment(['id' => $delivery->salesOrder->hash_id]);
        $this->getJson('/api/v1/supply-chain/deliveries/form-options?sales_order_id='.$delivery->salesOrder->hash_id)
            ->assertOk()
            ->assertJsonPath('data.selected_sales_order.items.0.remaining_quantity', '7.00')
            ->assertJsonMissingPath('data.selected_sales_order.items.0.unit_price');
        $this->getJson('/api/v1/supply-chain/deliveries/form-options?search=NO-MATCH')
            ->assertOk()->assertJsonCount(0, 'data.sales_orders');
        $warehouse = User::factory()->create(['role_id' => Role::where('slug', 'warehouse_staff')->value('id')]);
        $this->actingAs($warehouse, 'sanctum')->getJson('/api/v1/supply-chain/deliveries/form-options')->assertForbidden();
    }

    public function test_transferred_lot_can_be_dispatched_across_bins_without_duplicate_issues(): void
    {
        [$delivery, $receipt, $item, $source] = $this->arrange();
        $destination = WarehouseLocation::factory()->create(['zone_id' => $source->zone_id]);
        app(StockMovementService::class)->move(new StockMovementInput(
            type: StockMovementType::Transfer, itemId: $item->id, quantity: '8.000',
            fromLocationId: $source->id, toLocationId: $destination->id, lotNumber: $receipt->lot_number,
        ));
        $service = app(DeliveryService::class);
        $service->updateStatus($delivery, DeliveryStatus::InTransit);
        $line = $delivery->items()->firstOrFail();
        $this->assertSame(['2.000', '1.000'], $line->stockMovements()->orderBy('id')->pluck('quantity')->all());
        $this->assertSame(['APPROVED-BATCH'], $line->stockMovements()->pluck('lot_number')->unique()->all());
        $this->assertSame('0.000', (string) StockLevel::where('location_id', $source->id)->value('quantity'));
        $this->assertSame('7.000', (string) StockLevel::where('location_id', $destination->id)->value('quantity'));
        // Even a replay after restoring the old status cannot issue twice.
        Delivery::query()->whereKey($delivery->id)->update(['status' => DeliveryStatus::Loading->value]);
        $service->updateStatus($delivery->fresh(), DeliveryStatus::InTransit);
        $this->assertSame(2, $line->stockMovements()->count());
        $service->updateStatus($delivery->fresh(), DeliveryStatus::Delivered);
        $sources = app(\App\Modules\ReturnManagement\Services\ReturnCaseSourceService::class);
        $options = $sources->options($sources->resolve('delivery', $delivery->hash_id));
        $this->assertSame('APPROVED-BATCH', $options['lines'][0]['lot_number']);
    }

    public function test_short_approved_lot_rolls_back_partial_issues_and_preserves_other_lots(): void
    {
        [$delivery, $receipt, $item, $source] = $this->arrange(olderLot: true);
        $quarantine = WarehouseLocation::factory()->create([
            'zone_id' => WarehouseZone::factory()->create(['zone_type' => 'quarantine'])->id,
        ]);
        app(StockMovementService::class)->move(new StockMovementInput(
            type: StockMovementType::Transfer, itemId: $item->id, quantity: '8.000',
            fromLocationId: $source->id, toLocationId: $quarantine->id, lotNumber: $receipt->lot_number,
        ));
        try {
            app(DeliveryService::class)->updateStatus($delivery, DeliveryStatus::InTransit);
            $this->fail('Only two approved units are available outside quarantine.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('short by 1.000', $error->getMessage());
        }
        $this->assertSame(DeliveryStatus::Loading, $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->departed_at);
        $this->assertSame(0, $delivery->items()->firstOrFail()->stockMovements()->count());
        $this->assertSame('7.000', (string) StockLevel::where('location_id', $source->id)->value('quantity'));
    }

    public function test_untraceable_output_receipt_cannot_dispatch_generic_stock(): void
    {
        [$delivery] = $this->arrange();
        $delivery->items()->firstOrFail()->inspection->workOrderOutput
            ->update(['production_receipt_movement_id' => null]);
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('no traceable finished-goods receipt');
        app(DeliveryService::class)->updateStatus($delivery->fresh(), DeliveryStatus::InTransit);
    }

    public function test_dispatch_cannot_consume_stock_reserved_for_another_operation(): void
    {
        [$delivery, , $item, $source] = $this->arrange();
        app(StockMovementService::class)->reserve($item->id, $source->id, '8.000');
        try {
            app(DeliveryService::class)->updateStatus($delivery, DeliveryStatus::InTransit);
            $this->fail('Only two unreserved units are available for a three-unit dispatch.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('short by 1.000', $error->getMessage());
        }
        $level = StockLevel::where('location_id', $source->id)->firstOrFail();
        $this->assertSame('10.000', (string) $level->quantity);
        $this->assertSame('8.000', (string) $level->reserved_quantity);
        $this->assertSame(DeliveryStatus::Loading, $delivery->fresh()->status);
    }

    /** @return array{Delivery, StockMovement, Item, WarehouseLocation} */
    private function arrange(bool $secondBin = false, bool $olderLot = false): array
    {
        $actor = User::factory()->create();
        $checker = User::factory()->create();
        $customer = Customer::create(['name' => 'Dispatch provenance customer', 'is_active' => true]);
        $product = Product::factory()->create(['unit_of_measure' => 'pcs', 'standard_cost' => '4.50']);
        $so = SalesOrder::create([
            'so_number' => 'SO-DP-'.substr(uniqid(), -6), 'customer_id' => $customer->id,
            'date' => now()->toDateString(), 'subtotal' => '150.00', 'vat_amount' => '18.00',
            'total_amount' => '168.00', 'status' => 'confirmed', 'created_by' => $actor->id,
        ]);
        $soLine = SalesOrderItem::create([
            'sales_order_id' => $so->id, 'product_id' => $product->id, 'quantity' => '10.00',
            'unit_price' => '15.00', 'total' => '150.00', 'quantity_delivered' => '0.00',
            'delivery_date' => now()->addDay()->toDateString(),
        ]);
        $workOrder = WorkOrder::create([
            'wo_number' => 'WO-DP-'.substr(uniqid(), -6), 'product_id' => $product->id,
            'sales_order_id' => $so->id, 'sales_order_item_id' => $soLine->id,
            'quantity_target' => 10, 'quantity_good' => 10, 'quantity_produced' => 10,
            'planned_start' => now()->subDay(), 'planned_end' => now(),
            'status' => 'completed', 'created_by' => $actor->id,
        ]);
        $output = WorkOrderOutput::create([
            'work_order_id' => $workOrder->id, 'recorded_by' => $actor->id, 'recorded_at' => now(),
            'good_count' => 10, 'reject_count' => 0, 'batch_code' => 'APPROVED-BATCH',
        ]);
        $zone = WarehouseZone::factory()->create(['zone_type' => 'finished_goods']);
        $firstLocation = WarehouseLocation::factory()->create(['zone_id' => $zone->id]);
        $stockLocation = $secondBin
            ? WarehouseLocation::factory()->create(['zone_id' => $zone->id])
            : $firstLocation;
        $item = Item::factory()->create(['code' => $product->part_number, 'item_type' => 'finished_good']);
        StockLevel::factory()->create([
            'item_id' => $item->id, 'location_id' => $stockLocation->id,
            'quantity' => $olderLot ? '15.000' : '10.000', 'weighted_avg_cost' => '4.5000',
        ]);
        if ($secondBin) {
            StockLevel::factory()->create(['item_id' => $item->id, 'location_id' => $firstLocation->id]);
        }
        if ($olderLot) {
            StockMovement::create([
                'item_id' => $item->id, 'to_location_id' => $stockLocation->id,
                'movement_type' => 'opening', 'quantity' => '5.000', 'unit_cost' => '4.5000',
                'total_cost' => '22.50', 'lot_number' => 'UNAPPROVED-OLDER',
                'created_by' => $actor->id, 'created_at' => now()->subDay(),
            ]);
        }
        $receipt = StockMovement::create([
            'item_id' => $item->id, 'to_location_id' => $stockLocation->id,
            'movement_type' => 'production_receipt', 'quantity' => '10.000', 'unit_cost' => '4.5000',
            'total_cost' => '45.00', 'lot_number' => 'APPROVED-BATCH',
            'reference_type' => 'work_order_output', 'reference_id' => $output->id,
            'created_by' => $actor->id, 'created_at' => now(),
        ]);
        $output->update([
            'production_receipt_movement_id' => $receipt->id,
            'production_receipt_handoff_status' => 'generated',
        ]);
        $inspection = Inspection::create([
            'inspection_number' => 'QC-DP-'.substr(uniqid(), -6), 'stage' => 'outgoing', 'status' => 'passed',
            'inspector_id' => $actor->id, 'reviewed_by' => $checker->id, 'reviewed_at' => now(),
            'product_id' => $product->id, 'entity_type' => 'work_order', 'entity_id' => $workOrder->id,
            'work_order_output_id' => $output->id, 'batch_quantity' => 10, 'accepted_quantity' => 10,
            'sample_size' => 1, 'accept_count' => 1, 'reject_count' => 0, 'defect_count' => 0,
            'completed_at' => now(),
        ]);
        $delivery = app(DeliveryService::class)->create([
            'sales_order_id' => $so->id, 'scheduled_date' => now()->toDateString(),
            'items' => [['sales_order_item_id' => $soLine->id, 'quantity' => '3.00', 'inspection_id' => $inspection->id]],
        ], $actor);
        // Arrange the dispatch boundary; the full browser flow covers assignment/loading UI.
        $delivery->forceFill(['status' => DeliveryStatus::Loading])->save();

        return [$delivery, $receipt, $item, $firstLocation];
    }
}
