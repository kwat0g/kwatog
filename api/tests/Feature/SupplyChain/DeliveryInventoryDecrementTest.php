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
            'quantity' => '3.00',
            'unit_price' => '15.00',
        ]);

        return [$delivery, $location, $item];
    }
}
