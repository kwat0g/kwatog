<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCategory;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Production\Services\WorkOrderOutputService;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Services\DeliveryService;

/**
 * Records the finished-goods receipt a work-order output needs before its lots
 * can be dispatched. `DeliveryStockService::source()` refuses an approved output
 * without a `production_receipt` movement whose lot traces back to that output,
 * so fixtures that dispatch produced goods must arrive at stock the way
 * Production does instead of hand-stamping a lot.
 *
 * The receipt always goes through the real `WorkOrderOutputService` handoff, so
 * the lot number, stock level, output link and handoff status match the running
 * app. The item and bin lookup mirrors `WorkOrderOutputService`'s own selection
 * so the receipt lands where the service would have put it.
 */
trait ReceivesProductionOutput
{
    protected function receiveProductionOutput(WorkOrderOutput $output, User $actor, ?Product $product = null): WorkOrderOutput
    {
        $product ??= $output->workOrder()->first()?->product()->first();
        if (! $product) {
            throw new \RuntimeException('A production output needs its work-order product before it can be received.');
        }

        $this->finishedGoodsItemFor($product);
        $this->finishedGoodsLocation();

        return app(WorkOrderOutputService::class)->retryProductionReceipt($output, $actor);
    }

    /** The finished-goods inventory item for a product, created once per code. */
    protected function finishedGoodsItemFor(Product $product): Item
    {
        return Item::query()->firstOrCreate(
            ['code' => $product->part_number, 'item_type' => ItemType::FinishedGood->value],
            [
                'name' => $product->name,
                'category_id' => ItemCategory::factory()->create()->id,
                'unit_of_measure' => $product->unit_of_measure ?: 'pcs',
                'standard_cost' => $product->standard_cost ?: '0.0000',
                'is_active' => true,
            ],
        );
    }

    /** An active finished-goods bin, reusing whatever the fixture already has. */
    protected function finishedGoodsLocation(): WarehouseLocation
    {
        $zone = WarehouseZone::query()
            ->where('zone_type', WarehouseZoneType::FinishedGoods->value)
            ->orderBy('id')
            ->first();
        if (! $zone) {
            $zone = WarehouseZone::factory()->create([
                'code' => 'FG-TEST', 'name' => 'Finished goods (test)',
                'zone_type' => WarehouseZoneType::FinishedGoods->value,
            ]);
        }

        return WarehouseLocation::query()
            ->where('zone_id', $zone->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->first()
            ?? WarehouseLocation::factory()->create([
                'zone_id' => $zone->id, 'code' => 'FG-TEST-A', 'is_active' => true,
            ]);
    }

    /**
     * A delivery that is genuinely ready to depart: produced stock received into
     * a finished-goods bin, an independently reviewed outgoing inspection, and
     * the reservation `DeliveryService::create()` holds. The departure guard
     * refuses a delivery that has no durable reservation, so status-transition
     * fixtures cannot hand-build a bare row any more.
     *
     * @return array{0: Delivery, 1: Item, 2: WarehouseLocation}
     */
    protected function dispatchableDelivery(
        User $creator,
        string $quantity = '3.00',
        ?User $driver = null,
        ?\App\Modules\SupplyChain\Models\Vehicle $vehicle = null,
    ): array {
        $customer = Customer::create(['name' => 'Dispatchable customer '.uniqid(), 'is_active' => true]);
        $product = Product::create([
            'part_number' => 'FG-'.strtoupper(substr(uniqid(), -8)),
            'name' => 'Dispatchable product '.uniqid(),
            'unit_of_measure' => 'pcs',
            'standard_cost' => '4.50',
            'is_active' => true,
        ]);
        $order = SalesOrder::create([
            'so_number' => 'SO-DISP-'.strtoupper(substr(uniqid(), -8)),
            'customer_id' => $customer->id,
            'date' => now()->toDateString(),
            'subtotal' => '150.00', 'vat_amount' => '18.00', 'total_amount' => '168.00',
            'status' => 'confirmed', 'created_by' => $creator->id,
        ]);
        $line = SalesOrderItem::create([
            'sales_order_id' => $order->id, 'product_id' => $product->id,
            'quantity' => '10.00', 'unit_price' => '15.00', 'total' => '150.00',
            'quantity_delivered' => '0.00', 'delivery_date' => now()->addDay()->toDateString(),
        ]);
        $workOrder = WorkOrder::create([
            'wo_number' => 'WO-DISP-'.strtoupper(substr(uniqid(), -6)),
            'product_id' => $product->id, 'sales_order_id' => $order->id,
            'sales_order_item_id' => $line->id, 'quantity_target' => 10,
            'quantity_good' => 10, 'quantity_produced' => 10,
            'planned_start' => now()->subDay(), 'planned_end' => now(),
            'status' => 'completed', 'created_by' => $creator->id,
        ]);
        $output = WorkOrderOutput::create([
            'work_order_id' => $workOrder->id, 'recorded_by' => $creator->id, 'recorded_at' => now(),
            'good_count' => 10, 'reject_count' => 0,
            'batch_code' => 'DISP-'.strtoupper(substr(uniqid(), -8)),
        ]);
        $output = $this->receiveProductionOutput($output, $creator, $product);

        $inspection = Inspection::create([
            'inspection_number' => 'QC-DISP-'.strtoupper(substr(uniqid(), -6)),
            'stage' => InspectionStage::Outgoing->value, 'status' => InspectionStatus::Passed->value,
            'inspector_id' => $creator->id, 'reviewed_by' => User::factory()->create()->id,
            'reviewed_at' => now(), 'product_id' => $product->id,
            'entity_type' => InspectionEntityType::WorkOrder->value, 'entity_id' => $workOrder->id,
            'work_order_output_id' => $output->id, 'batch_quantity' => 10, 'accepted_quantity' => 10,
            'sample_size' => 1, 'accept_count' => 1, 'reject_count' => 0, 'defect_count' => 0,
            'completed_at' => now(),
        ]);

        $delivery = app(DeliveryService::class)->create([
            'sales_order_id' => $order->id,
            'scheduled_date' => now()->toDateString(),
            'driver_id' => $driver?->id,
            'vehicle_id' => $vehicle?->id,
            'items' => [[
                'sales_order_item_id' => $line->id,
                'quantity' => $quantity,
                'inspection_id' => $inspection->id,
            ]],
        ], $creator);

        return [$delivery, $this->finishedGoodsItemFor($product), $this->finishedGoodsLocation()];
    }
}
