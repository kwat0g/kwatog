<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryItem;
use App\Modules\SupplyChain\Services\DeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DeliveryInventoryDecrementTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_decrements_finished_goods_and_links_the_stock_movement(): void
    {
        [$delivery, $location, $item] = $this->deliveryWithFinishedGoodsStock();
        $deliveryItem = $delivery->items()->firstOrFail();

        $delivery->forceFill(['status' => DeliveryStatus::Loading->value])->save();
        app(DeliveryService::class)->updateStatus($delivery, DeliveryStatus::InTransit);

        $level = StockLevel::query()->where('item_id', $item->id)->where('location_id', $location->id)->firstOrFail();
        $movement = $deliveryItem->fresh()->stockMovement;

        $this->assertSame('7.000', (string) $level->quantity);
        $this->assertNotNull($movement);
        $this->assertSame('delivery', $movement->movement_type->value);
        $this->assertSame('delivery_item', $movement->reference_type);
        $this->assertSame($deliveryItem->id, $movement->reference_id);
    }

    public function test_retrying_dispatch_does_not_create_a_second_stock_movement(): void
    {
        [$delivery, $location, $item] = $this->deliveryWithFinishedGoodsStock();
        $deliveryItem = $delivery->items()->firstOrFail();

        Delivery::query()->whereKey($delivery->id)->update(['status' => DeliveryStatus::Loading->value]);
        $service = app(DeliveryService::class);
        $service->updateStatus($delivery, DeliveryStatus::InTransit);

        Delivery::query()->whereKey($delivery->id)->update(['status' => DeliveryStatus::Loading->value]);
        $service->updateStatus($delivery->fresh(), DeliveryStatus::InTransit);

        $this->assertSame(1, $deliveryItem->fresh()->stockMovement()->count());
        $this->assertSame('7.000', (string) StockLevel::query()
            ->where('item_id', $item->id)
            ->where('location_id', $location->id)
            ->value('quantity'));
    }

    /** @return array{0: Delivery, 1: WarehouseLocation, 2: Item} */
    private function deliveryWithFinishedGoodsStock(): array
    {
        $user = User::factory()->create();
        $customer = Customer::create(['name' => 'Delivery stock customer '.uniqid(), 'is_active' => true]);
        $product = Product::create([
            'part_number' => 'FG-'.substr(uniqid(), -6),
            'name' => 'Finished good',
            'unit_of_measure' => 'pcs',
            'standard_cost' => '4.50',
            'is_active' => true,
        ]);
        $so = SalesOrder::create([
            'so_number' => 'SO-STOCK-'.substr(uniqid(), -6),
            'customer_id' => $customer->id,
            'date' => now()->toDateString(),
            'subtotal' => '45.00',
            'vat_amount' => '5.40',
            'total_amount' => '50.40',
            'status' => 'confirmed',
            'created_by' => $user->id,
        ]);
        $soItem = SalesOrderItem::create([
            'sales_order_id' => $so->id,
            'product_id' => $product->id,
            'quantity' => '3.00',
            'unit_price' => '15.00',
            'total' => '45.00',
            'quantity_delivered' => '0.00',
            'delivery_date' => now()->toDateString(),
        ]);
        $item = Item::factory()->create([
            'code' => $product->part_number,
            'item_type' => ItemType::FinishedGood->value,
            'is_active' => true,
        ]);
        $zone = WarehouseZone::factory()->create(['zone_type' => 'finished_goods']);
        $location = WarehouseLocation::factory()->create(['zone_id' => $zone->id, 'is_active' => true]);
        StockLevel::factory()->create([
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity' => '10.000',
            'weighted_avg_cost' => '4.5000',
        ]);

        // Real dispatch evidence: this stock belongs to the independently
        // reviewed output on this order, rather than anonymous legacy stock.
        $workOrder = WorkOrder::create([
            'wo_number' => 'WO-STOCK-'.substr(uniqid(), -6), 'product_id' => $product->id,
            'sales_order_id' => $so->id, 'sales_order_item_id' => $soItem->id,
            'quantity_target' => 10, 'quantity_good' => 10, 'quantity_produced' => 10,
            'planned_start' => now()->subDay(), 'planned_end' => now(),
            'status' => 'completed', 'created_by' => $user->id,
        ]);
        $output = WorkOrderOutput::create([
            'work_order_id' => $workOrder->id, 'recorded_by' => $user->id, 'recorded_at' => now(),
            'good_count' => 10, 'reject_count' => 0, 'batch_code' => 'FG-STOCK-BATCH',
        ]);
        $receipt = StockMovement::create([
            'item_id' => $item->id, 'to_location_id' => $location->id,
            'movement_type' => 'production_receipt', 'quantity' => '10.000', 'unit_cost' => '4.5000',
            'total_cost' => '45.00', 'lot_number' => $output->batch_code,
            'reference_type' => 'work_order_output', 'reference_id' => $output->id,
            'created_by' => $user->id, 'created_at' => now(),
        ]);
        $output->update(['production_receipt_movement_id' => $receipt->id, 'production_receipt_handoff_status' => 'generated']);
        $inspection = Inspection::create([
            'inspection_number' => 'QC-STOCK-'.substr(uniqid(), -6), 'stage' => 'outgoing', 'status' => 'passed',
            'inspector_id' => $user->id, 'reviewed_by' => User::factory()->create()->id, 'reviewed_at' => now(),
            'product_id' => $product->id, 'entity_type' => 'work_order', 'entity_id' => $workOrder->id,
            'work_order_output_id' => $output->id, 'batch_quantity' => 10, 'accepted_quantity' => 10,
            'sample_size' => 1, 'accept_count' => 1, 'reject_count' => 0, 'defect_count' => 0, 'completed_at' => now(),
        ]);

        $delivery = Delivery::create([
            'delivery_number' => 'DL-STOCK-'.substr(uniqid(), -6),
            'sales_order_id' => $so->id,
            'status' => DeliveryStatus::Scheduled->value,
            'scheduled_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);
        DeliveryItem::create([
            'delivery_id' => $delivery->id,
            'sales_order_item_id' => $soItem->id,
            'inspection_id' => $inspection->id,
            'quantity' => '3.00',
            'unit_price' => '15.00',
        ]);

        return [$delivery, $location, $item];
    }
}
