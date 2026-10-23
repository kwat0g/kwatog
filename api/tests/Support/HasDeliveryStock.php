<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Enums\MovementGlHandoffStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\MovementGlPostingService;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Models\Inspection;
use App\Modules\SupplyChain\Services\DeliveryService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\SettingsSeeder;

/** Approved historical production stock for focused delivery lifecycle tests. */
trait HasDeliveryStock
{
    use HasPassedInProcessQc;

    /**
     * @param  Customer|null  $customer  Optional customer to put on the sales
     *     order. Callers that assert delivery-time data copied off the customer
     *     (e.g. the ship-to snapshot) need the customer to exist before the
     *     delivery is created; passing null keeps a factory-made one.
     */
    protected function deliveryWithApprovedStock(User $actor, ?Customer $customer = null): array
    {
        $this->seed([SettingsSeeder::class, ChartOfAccountsSeeder::class]);
        $checker = User::factory()->create();
        $product = Product::factory()->create(['standard_cost' => '4.50']);
        $order = SalesOrder::factory()->create([
            'created_by' => $actor->id, 'status' => 'confirmed', 'date' => today()->toDateString(),
            'subtotal' => '45.00', 'vat_amount' => '5.40', 'total_amount' => '50.40',
        ] + ($customer !== null ? ['customer_id' => $customer->id] : []));
        $line = SalesOrderItem::factory()->create([
            'sales_order_id' => $order->id, 'product_id' => $product->id,
            'quantity' => '3.00', 'unit_price' => '15.00', 'total' => '45.00',
            'delivery_date' => today()->toDateString(),
        ]);
        $workOrder = WorkOrder::factory()->create([
            'product_id' => $product->id, 'sales_order_id' => $order->id, 'sales_order_item_id' => $line->id,
            'quantity_target' => 3, 'quantity_good' => 3, 'quantity_produced' => 3,
            'planned_start' => now()->subDay(), 'planned_end' => now(), 'status' => 'completed', 'created_by' => $actor->id,
        ]);
        $this->passInProcessQc($workOrder);
        $output = WorkOrderOutput::create([
            'work_order_id' => $workOrder->id, 'recorded_by' => $actor->id, 'recorded_at' => now(),
            'good_count' => 3, 'reject_count' => 0, 'batch_code' => 'DISPATCH-'.uniqid(),
        ]);
        $location = WarehouseLocation::factory()->create([
            'zone_id' => WarehouseZone::factory()->create(['zone_type' => 'finished_goods'])->id,
        ]);
        $item = Item::factory()->create(['code' => $product->part_number, 'item_type' => 'finished_good']);
        StockLevel::factory()->create([
            'item_id' => $item->id, 'location_id' => $location->id, 'quantity' => '3.000', 'weighted_avg_cost' => '4.5000',
        ]);
        $receipt = StockMovement::create([
            'item_id' => $item->id, 'to_location_id' => $location->id, 'movement_type' => 'production_receipt',
            'quantity' => '3.000', 'unit_cost' => '4.5000', 'total_cost' => '13.50', 'lot_number' => $output->batch_code,
            'reference_type' => 'work_order_output', 'reference_id' => $output->id, 'created_by' => $actor->id,
        ]);
        app(MovementGlPostingService::class)->postFor($receipt);
        $this->assertSame(MovementGlHandoffStatus::Generated, $receipt->fresh()->gl_handoff_status);
        $output->update(['production_receipt_movement_id' => $receipt->id, 'production_receipt_handoff_status' => 'generated']);
        $inspection = Inspection::create([
            'inspection_number' => 'QC-DISPATCH-'.uniqid(), 'stage' => 'outgoing', 'status' => 'passed',
            'inspector_id' => $actor->id, 'reviewed_by' => $checker->id, 'reviewed_at' => now(),
            'product_id' => $product->id, 'entity_type' => 'work_order', 'entity_id' => $workOrder->id,
            'work_order_output_id' => $output->id, 'batch_quantity' => 3, 'accepted_quantity' => 3,
            'sample_size' => 1, 'accept_count' => 1, 'reject_count' => 0, 'defect_count' => 0, 'completed_at' => now(),
        ]);
        $delivery = app(DeliveryService::class)->create([
            'sales_order_id' => $order->id, 'scheduled_date' => today()->toDateString(),
            'items' => [['sales_order_item_id' => $line->id, 'quantity' => '3.00', 'inspection_id' => $inspection->id]],
        ], $actor);

        return [$delivery, $item, $location];
    }
}
