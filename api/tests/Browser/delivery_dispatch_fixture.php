<?php

declare(strict_types=1);

// Approved output is the starting boundary; the browser creates every delivery.
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Production\Services\WorkOrderOutputService;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\SupplyChain\Models\Vehicle;

$database = (string) config('database.connections.pgsql.database');
if (! str_starts_with($database, 'ogami_test_dispatch_browser_') || $database !== getenv('DISPATCH_BROWSER_DB')) {
    throw new RuntimeException('Select a dedicated dispatch browser database explicitly.');
}
// Fixture seeding must never populate the shared runtime settings cache.
config(['cache.default' => 'array']);
$run = strtoupper(preg_replace('/[^a-z0-9]/i', '', getenv('DISPATCH_RUN_ID') ?: ''));
if (strlen($run) < 6 || strlen($run) > 16) throw new RuntimeException('Use a unique 6–16 character DISPATCH_RUN_ID.');
if (Product::where('part_number', 'FG-'.$run)->exists()) throw new RuntimeException('Use a fresh database and run identifier.');
foreach ([
    Database\Seeders\RolePermissionSeeder::class, Database\Seeders\SettingsSeeder::class,
    Database\Seeders\ChartOfAccountsSeeder::class, Database\Seeders\DepartmentSeeder::class,
    Database\Seeders\PositionSeeder::class, Database\Seeders\DemoAccountSeeder::class,
    Database\Seeders\WorkflowSeeder::class,
] as $seeder) (new $seeder)->run();
foreach (['supply_chain', 'inventory', 'quality', 'b2b', 'return_management'] as $module) {
    app(SettingsService::class)->set('modules.'.$module, true, 'modules');
}
$manager = User::where('email', 'production@ogami.test')->firstOrFail();
$qc = User::where('email', 'qc@ogami.test')->firstOrFail();
$driver = User::where('email', 'driver@ogami.test')->firstOrFail();
$customer = Customer::factory()->create(['name' => 'Dispatch customer '.$run]);
$portal = CustomerPortalUser::create([
    'customer_id' => $customer->id, 'name' => 'Receiving contact '.$run,
    'email' => 'customer.dispatch@ogami.test', 'password' => 'password', 'is_active' => true,
    'must_change_password' => false, 'password_changed_at' => now(),
]);
CustomerPortalUser::create([
    'customer_id' => Customer::factory()->create()->id, 'name' => 'Other customer',
    'email' => 'other.dispatch@ogami.test', 'password' => 'password', 'is_active' => true,
    'must_change_password' => false, 'password_changed_at' => now(),
]);
$product = Product::factory()->create(['part_number' => 'FG-'.$run, 'name' => 'Dispatch audit part', 'unit_of_measure' => 'pcs', 'standard_cost' => '4.50']);
$item = Item::factory()->create(['code' => $product->part_number, 'name' => $product->name, 'item_type' => 'finished_good', 'unit_of_measure' => 'pcs']);
$zone = WarehouseZone::factory()->create(['zone_type' => 'finished_goods']);
$locations = collect(['A', 'B', 'C'])->map(fn ($code) => WarehouseLocation::factory()->create(['zone_id' => $zone->id, 'code' => 'FG-'.$code, 'is_active' => true]));
$stock = app(StockMovementService::class);
$stock->move(new StockMovementInput(type: StockMovementType::Opening, itemId: $item->id, quantity: '5.000', toLocationId: $locations[0]->id, unitCost: '4.5000', lotNumber: 'UNAPPROVED-'.$run));
$order = SalesOrder::factory()->create([
    'so_number' => 'SO-'.$run, 'customer_id' => $customer->id, 'created_by' => $manager->id,
    'status' => 'confirmed', 'subtotal' => '150.00', 'vat_amount' => '18.00', 'total_amount' => '168.00',
]);
$line = SalesOrderItem::factory()->create(['sales_order_id' => $order->id, 'product_id' => $product->id, 'quantity' => '10.00', 'quantity_delivered' => '0.00', 'unit_price' => '15.00', 'total' => '150.00']);
$workOrder = WorkOrder::create([
    'wo_number' => 'WO-'.$run, 'product_id' => $product->id, 'sales_order_id' => $order->id, 'sales_order_item_id' => $line->id,
    'quantity_target' => 10, 'quantity_good' => 10, 'quantity_produced' => 10,
    'planned_start' => now()->subDay(), 'planned_end' => now(), 'status' => 'completed',
    'batch_number' => 'WO-BATCH-'.$run, 'created_by' => $manager->id,
]);
$batches = [];
foreach ([6, 4] as $index => $quantity) {
    $output = WorkOrderOutput::create([
        'work_order_id' => $workOrder->id, 'recorded_by' => $manager->id, 'recorded_at' => now(),
        'good_count' => $quantity, 'reject_count' => 0, 'batch_code' => $run.'-'.($index + 1),
    ]);
    // Exercise the actual production receipt handoff; never hand-stamp a lot.
    $output = app(WorkOrderOutputService::class)->retryProductionReceipt($output, $manager);
    if ($output->productionReceiptMovement?->lot_number !== $output->batch_code) throw new RuntimeException('Production receipt lost its batch lot.');
    $inspection = Inspection::create([
        'inspection_number' => 'QC-'.$run.'-'.$index, 'stage' => 'outgoing', 'status' => 'passed',
        'inspector_id' => $manager->id, 'reviewed_by' => $qc->id, 'reviewed_at' => now(),
        'product_id' => $product->id, 'entity_type' => 'work_order', 'entity_id' => $workOrder->id,
        'work_order_output_id' => $output->id, 'batch_quantity' => $quantity, 'accepted_quantity' => $quantity,
        'sample_size' => 1, 'accept_count' => 1, 'reject_count' => 0, 'defect_count' => 0, 'completed_at' => now(),
    ]);
    InspectionMeasurement::create([
        'inspection_id' => $inspection->id, 'sample_index' => 1, 'parameter_name' => 'Visual condition',
        'parameter_type' => 'visual', 'is_critical' => true, 'is_pass' => true,
        'notes' => 'No visible defects in the sampled unit; prerequisite evidence for the reviewed output.',
    ]);
    if ($index === 0) {
        $stock->move(new StockMovementInput(type: StockMovementType::Transfer, itemId: $item->id, quantity: '6.000', fromLocationId: $locations[0]->id, toLocationId: $locations[1]->id, lotNumber: $output->batch_code));
        $stock->move(new StockMovementInput(type: StockMovementType::Transfer, itemId: $item->id, quantity: '4.000', fromLocationId: $locations[1]->id, toLocationId: $locations[2]->id, lotNumber: $output->batch_code));
    }
    $batches[] = ['inspection' => $inspection->hash_id, 'lot' => $output->batch_code, 'quantity' => $quantity];
}
$vehicle = Vehicle::create(['plate_number' => 'DSP-'.$run, 'name' => 'Dispatch audit truck', 'vehicle_type' => 'truck', 'status' => 'available']);
$fixture = ['run_id' => $run, 'database' => $database, 'sales_order' => $order->hash_id, 'so_number' => $order->so_number, 'line' => $line->hash_id,
    'item' => $item->hash_id, 'product_code' => $product->part_number, 'batches' => $batches,
    'vehicle' => $vehicle->hash_id, 'driver' => $driver->hash_id, 'customer_email' => $portal->email,
    'locations' => $locations->map(fn ($location) => ['id' => $location->hash_id, 'code' => $location->full_code])->all()];
file_put_contents(getenv('DISPATCH_FIXTURE_PATH') ?: '/tmp/dispatch-fixture.json', json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Dispatch prerequisites ready: {$run}.\n";
