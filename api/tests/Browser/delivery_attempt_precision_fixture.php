<?php

declare(strict_types=1);

// Starting state: confirmed order, one approved outgoing lot, and available
// stock. The browser creates and dispatches the delivery, then reports outcome.
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Models\Inspection;
use App\Modules\SupplyChain\Models\Vehicle;
use Illuminate\Support\Facades\DB;

$tag = (string) getenv('E2E_DELIVERY_PRECISION_TAG');
if (! preg_match('/^[a-z0-9-]{1,6}$/D', $tag)) {
    throw new RuntimeException('Set E2E_DELIVERY_PRECISION_TAG to an unused 1–6 character lowercase tag.');
}
if (app()->environment() !== 'local' || DB::connection()->getDatabaseName() !== 'ogami_test_audit_20261002') {
    throw new RuntimeException('Refusing delivery fixtures outside the isolated audit database.');
}
if (SalesOrder::query()->where('so_number', "SO-DP-{$tag}")->exists()) {
    throw new RuntimeException("Delivery precision fixture {$tag} already exists; use a fresh tag.");
}

$fixture = DB::transaction(function () use ($tag): array {
    $impex = User::query()->where('email', 'impex@ogami.test')->firstOrFail();
    $qc = User::query()->where('email', 'qc@ogami.test')->firstOrFail();
    $production = User::query()->where('email', 'production@ogami.test')->firstOrFail();
    $driverRoleId = User::query()->where('email', 'driver@ogami.test')->firstOrFail()->role_id;
    $driverEmail = "driver.precision.{$tag}@ogami.test";
    $driver = User::factory()->create([
        'name' => "Delivery precision driver {$tag}",
        'email' => $driverEmail,
        'role_id' => $driverRoleId,
        'is_active' => true,
    ]);
    $revenue = Account::query()->where('code', '4010')->firstOrFail();
    $customer = Customer::query()->create([
        'name' => "Delivery precision E2E {$tag}",
        'payment_terms_days' => 30,
        'is_active' => true,
    ]);
    $product = Product::factory()->create([
        'part_number' => "DP-{$tag}-KG",
        'name' => "Delivery precision finished good {$tag}",
        'unit_of_measure' => 'kg',
        'standard_cost' => '4.50',
        'revenue_account_id' => $revenue->id,
        'is_active' => true,
    ]);
    $item = Item::factory()->create([
        'code' => $product->part_number,
        'name' => $product->name,
        'item_type' => 'finished_good',
        'unit_of_measure' => 'kg',
        'is_active' => true,
    ]);
    $warehouse = Warehouse::factory()->create(['is_active' => true]);
    $zone = WarehouseZone::factory()->create([
        'warehouse_id' => $warehouse->id,
        'zone_type' => 'finished_goods',
    ]);
    $location = WarehouseLocation::factory()->create([
        'zone_id' => $zone->id,
        'is_active' => true,
        'is_blocked' => false,
    ]);
    StockLevel::factory()->create([
        'item_id' => $item->id,
        'location_id' => $location->id,
        'quantity' => '1.000',
        'weighted_avg_cost' => '4.5000',
    ]);

    $order = SalesOrder::factory()->create([
        'so_number' => "SO-DP-{$tag}",
        'customer_id' => $customer->id,
        'created_by' => $impex->id,
        'status' => 'confirmed',
    ]);
    $line = SalesOrderItem::factory()->create([
        'sales_order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => '1.00',
        'quantity_delivered' => '0.00',
        'unit_price' => '15.00',
        'total' => '15.00',
    ]);
    $workOrder = WorkOrder::query()->create([
        'wo_number' => "WO-DP-{$tag}",
        'product_id' => $product->id,
        'sales_order_id' => $order->id,
        'sales_order_item_id' => $line->id,
        'quantity_target' => 1,
        'quantity_good' => 1,
        'quantity_produced' => 1,
        'planned_start' => now()->subDay(),
        'planned_end' => now(),
        'status' => 'completed',
        'created_by' => $impex->id,
    ]);
    Inspection::query()->create([
        'inspection_number' => "QC-DP-IP-{$tag}",
        'stage' => 'in_process',
        'status' => 'passed',
        'inspector_id' => $impex->id,
        'reviewed_by' => $production->id,
        'reviewed_at' => now(),
        'completed_at' => now(),
        'product_id' => $product->id,
        'entity_type' => 'work_order',
        'entity_id' => $workOrder->id,
        'batch_quantity' => 1,
        'accepted_quantity' => 1,
        'sample_size' => 1,
        'accept_count' => 1,
        'reject_count' => 0,
        'defect_count' => 0,
    ]);
    $output = WorkOrderOutput::query()->create([
        'work_order_id' => $workOrder->id,
        'recorded_by' => $impex->id,
        'recorded_at' => now(),
        'good_count' => 1,
        'reject_count' => 0,
        'batch_code' => "DP-LOT-{$tag}",
    ]);
    $receipt = StockMovement::query()->create([
        'item_id' => $item->id,
        'to_location_id' => $location->id,
        'movement_type' => StockMovementType::ProductionReceipt->value,
        'quantity' => '1.000',
        'unit_cost' => '4.5000',
        'total_cost' => '4.50',
        'reference_type' => 'work_order_output',
        'reference_id' => $output->id,
        'lot_number' => $output->batch_code,
        'created_by' => $impex->id,
        'created_at' => now(),
    ]);
    $output->update([
        'production_receipt_movement_id' => $receipt->id,
        'production_receipt_handoff_status' => 'generated',
    ]);
    $inspection = Inspection::query()->create([
        'inspection_number' => "QC-DP-OUT-{$tag}",
        'stage' => 'outgoing',
        'status' => 'passed',
        'inspector_id' => $impex->id,
        'reviewed_by' => $qc->id,
        'reviewed_at' => now(),
        'product_id' => $product->id,
        'entity_type' => 'work_order',
        'entity_id' => $workOrder->id,
        'work_order_output_id' => $output->id,
        'batch_quantity' => 1,
        'accepted_quantity' => 1,
        'sample_size' => 1,
        'accept_count' => 1,
        'reject_count' => 0,
        'defect_count' => 0,
        'completed_at' => now(),
    ]);
    $vehicle = Vehicle::query()->create([
        'plate_number' => "DP-{$tag}",
        'name' => "Delivery precision truck {$tag}",
        'vehicle_type' => 'truck',
        'status' => 'available',
    ]);

    return [
        'tag' => $tag,
        'database' => DB::connection()->getDatabaseName(),
        'sales_order' => $order->hash_id,
        'sales_order_number' => $order->so_number,
        'sales_order_line' => $line->hash_id,
        'inspection' => $inspection->hash_id,
        'item' => $item->hash_id,
        'quantity' => '1.000',
        'vehicle' => $vehicle->hash_id,
        'driver' => $driver->hash_id,
        'driver_email' => $driverEmail,
    ];
});

$manifest = storage_path("app/delivery-attempt-precision-e2e-{$tag}.json");
file_put_contents($manifest, json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($fixture, JSON_THROW_ON_ERROR), PHP_EOL;
