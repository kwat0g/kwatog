<?php

declare(strict_types=1);

// Create starting prerequisites only on the isolated Production browser DB.
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\BomItem;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Enums\ProductionScheduleStatus;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use Illuminate\Support\Facades\DB;

$database = (string) config('database.connections.pgsql.database');
$expectedDatabase = getenv('PRODUCTION_WORKORDERS_BROWSER_DB') ?: 'ogami_test_production_workorders_browser_audit_0925';
if (! str_starts_with($expectedDatabase, 'ogami_test_production_workorders_browser_') || $database !== $expectedDatabase) {
    throw new RuntimeException('Production browser fixtures require the explicitly selected isolated browser-test database; got '.$database);
}
config(['cache.default' => 'array']);

$runId = strtoupper(preg_replace('/[^a-z0-9]/i', '', getenv('PRODUCTION_WORKORDERS_RUN_ID') ?: ''));
if (strlen($runId) < 6) {
    throw new RuntimeException('Set PRODUCTION_WORKORDERS_RUN_ID to a unique 6+ character run key.');
}
$suffix = substr($runId, -8);
$productCode = 'PWO-'.$suffix;
if (Product::query()->where('part_number', $productCode)->exists()) {
    throw new RuntimeException('Run key already exists in this browser DB; choose a new run key instead of reusing completed work.');
}

foreach ([
    Database\Seeders\RolePermissionSeeder::class,
    Database\Seeders\SettingsSeeder::class,
    Database\Seeders\DepartmentSeeder::class,
    Database\Seeders\PositionSeeder::class,
    Database\Seeders\DemoAccountSeeder::class,
    Database\Seeders\WorkflowSeeder::class,
] as $seeder) {
    (new $seeder)->run();
}

$settings = app(SettingsService::class);
$settings->set('modules.production', true, 'modules');
$settings->set('modules.inventory', true, 'modules');
$settings->set('modules.quality', true, 'modules');
$settings->set('modules.maintenance', true, 'modules');

$manager = User::query()->where('email', 'production@ogami.test')->firstOrFail();
$ppc = User::query()->where('email', 'ppc@ogami.test')->firstOrFail();
$warehouseUser = User::query()->where('email', 'warehouse@ogami.test')->firstOrFail();
$qc = User::query()->where('email', 'qc@ogami.test')->firstOrFail();
$qcChecker = User::factory()->withRole('qc_inspector')->create([
    'name' => 'Independent QC checker '.$suffix,
    'email' => 'pwo-qc-checker-'.$suffix.'@ogami.test',
]);
if (! $qcChecker->hasPermission('quality.inspections.review')) {
    throw new RuntimeException('The seeded QC Inspector role must be eligible to review inspections.');
}

$product = Product::query()->create([
    'part_number' => $productCode,
    'name' => 'Work-order browser audit part '.$suffix,
    'unit_of_measure' => 'pcs',
    'standard_cost' => '12.00',
    'is_active' => true,
]);
$rawItem = Item::factory()->create([
    'code' => 'RAW-'.$suffix,
    'name' => 'Work-order audit resin '.$suffix,
    'unit_of_measure' => 'KG',
    'item_type' => 'raw_material',
    'standard_cost' => '4.00',
    'reorder_point' => '0',
    'safety_stock' => '0',
    'is_active' => true,
]);
$finishedItem = Item::factory()->create([
    'code' => $productCode,
    'name' => $product->name,
    'unit_of_measure' => 'pcs',
    'item_type' => 'finished_good',
    'standard_cost' => '12.00',
    'reorder_point' => '0',
    'safety_stock' => '0',
    'is_active' => true,
]);
$bom = Bom::query()->create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);
BomItem::query()->create([
    'bom_id' => $bom->id,
    'item_id' => $rawItem->id,
    'quantity_per_unit' => '1.0000',
    'unit' => 'KG',
    'waste_factor' => '0.00',
    'sort_order' => 0,
]);

$machine = Machine::query()->create([
    'machine_code' => 'PWO-M-'.$suffix,
    'name' => 'Audit injection machine '.$suffix,
    'tonnage' => 120,
    'machine_type' => 'injection_molder',
    'operators_required' => '1.0',
    'available_hours_per_day' => '16.0',
    'status' => 'idle',
]);
$mold = Mold::query()->create([
    'mold_code' => 'PWO-D-'.$suffix,
    'name' => 'Audit mold '.$suffix,
    'product_id' => $product->id,
    'cavity_count' => 2,
    'cycle_time_seconds' => 30,
    'output_rate_per_hour' => 240,
    'setup_time_minutes' => 15,
    'current_shot_count' => 0,
    'max_shots_before_maintenance' => 100000,
    'lifetime_max_shots' => 1000000,
    'status' => 'available',
]);
DB::table('mold_machine_compatibility')->insert([
    'mold_id' => $mold->id,
    'machine_id' => $machine->id,
]);

$warehouse = Warehouse::factory()->create([
    'code' => 'PWO-WH-'.$suffix,
    'name' => 'Production work-order audit warehouse '.$suffix,
    'is_active' => true,
]);
$rawZone = WarehouseZone::factory()->create([
    'warehouse_id' => $warehouse->id,
    'code' => 'RAW',
    'name' => 'Raw materials',
    'zone_type' => 'raw_materials',
]);
$fgZone = WarehouseZone::factory()->create([
    'warehouse_id' => $warehouse->id,
    'code' => 'FG',
    'name' => 'Finished goods',
    'zone_type' => 'finished_goods',
]);
$rawLocation = WarehouseLocation::factory()->create([
    'zone_id' => $rawZone->id,
    'code' => 'PWO-RAW-'.$suffix,
    'is_active' => true,
    'is_blocked' => false,
]);
$fgLocation = WarehouseLocation::factory()->create([
    'zone_id' => $fgZone->id,
    'code' => 'PWO-FG-'.$suffix,
    'is_active' => true,
    'is_blocked' => false,
]);
app(StockMovementService::class)->move(new StockMovementInput(
    type: StockMovementType::Opening,
    itemId: $rawItem->id,
    quantity: '9.000',
    toLocationId: $rawLocation->id,
    unitCost: '4.0000',
    remarks: 'Production work-order browser fixture opening stock',
    createdBy: $warehouseUser->id,
    lotNumber: 'PWO-LOT-'.$suffix,
));

$salesOrder = SalesOrder::factory()->create([
    'so_number' => 'SO-PWO-'.$suffix,
    'created_by' => $ppc->id,
    'status' => 'confirmed',
    'subtotal' => '84.00',
    'vat_amount' => '10.08',
    'total_amount' => '94.08',
    'confirmed_at' => now(),
]);
$salesOrderItem = SalesOrderItem::query()->create([
    'sales_order_id' => $salesOrder->id,
    'product_id' => $product->id,
    'quantity' => '7.00',
    'unit_price' => '12.00',
    'total' => '84.00',
    'quantity_delivered' => '0.00',
    'delivery_date' => now()->addDays(30)->toDateString(),
]);

$routing = App\Modules\Production\Models\ProductRouting::query()->create([
    'product_id' => $product->id,
    'version' => 1,
    'is_active' => true,
    'notes' => 'Audit route for work-order execution.',
]);
App\Modules\Production\Models\RoutingOperation::query()->create([
    'routing_id' => $routing->id,
    'sequence' => 10,
    'operation_name' => 'Injection moulding',
    'work_center' => $machine->machine_code,
    'machine_id' => $machine->id,
    'mold_id' => $mold->id,
    'setup_time_minutes' => '15.00',
    'cycle_time_minutes' => '0.50',
    'labor_rate_per_hour' => '120.0000',
    'machine_rate_per_hour' => '250.0000',
    'overhead_rate_per_hour' => '80.0000',
    'description' => 'Run the audit batch and report operation quantity.',
    'qc_required' => true,
]);

$spec = InspectionSpec::query()->create([
    'product_id' => $product->id,
    'version' => 1,
    'is_active' => true,
    'created_by' => $qc->id,
]);
InspectionSpecItem::query()->create([
    'inspection_spec_id' => $spec->id,
    'parameter_name' => 'Outer diameter',
    'parameter_type' => 'dimensional',
    'unit_of_measure' => 'mm',
    'nominal_value' => '10.0000',
    'tolerance_min' => '9.9000',
    'tolerance_max' => '10.1000',
    'is_critical' => true,
]);
$defect = DefectType::query()->create([
    'code' => 'D'.$suffix,
    'name' => 'Audit surface mark',
    'description' => 'Browser-run reject classification.',
    'is_active' => true,
]);
$shift = Shift::query()->create([
    'name' => 'PWO shift '.$suffix,
    'start_time' => '07:00',
    'end_time' => '16:00',
    'break_minutes' => 60,
    'grace_minutes' => 0,
    'is_active' => true,
]);

$workOrders = app(WorkOrderService::class);
$plannedStart = now()->addDay()->startOfHour();
$recoveryWo = $workOrders->createDraft([
    'product_id' => $product->id,
    'sales_order_id' => $salesOrder->id,
    'sales_order_item_id' => $salesOrderItem->id,
    'quantity_target' => 2,
    'planned_start' => $plannedStart,
    'planned_end' => $plannedStart->copy()->addHours(2),
    'created_by' => $ppc->id,
]);
$mainWo = $workOrders->createDraft([
    'product_id' => $product->id,
    'sales_order_id' => $salesOrder->id,
    'sales_order_item_id' => $salesOrderItem->id,
    'quantity_target' => 5,
    'planned_start' => $plannedStart->copy()->addHours(3),
    'planned_end' => $plannedStart->copy()->addHours(5),
    'created_by' => $manager->id,
]);
$autoReturnWo = $workOrders->createDraft([
    'product_id' => $product->id,
    'quantity_target' => 1,
    'planned_start' => $plannedStart->copy()->addHours(6),
    'planned_end' => $plannedStart->copy()->addHours(8),
    'created_by' => $manager->id,
]);
foreach ([
    [$recoveryWo, $plannedStart, $plannedStart->copy()->addHours(2)],
    [$mainWo, $plannedStart->copy()->addHours(3), $plannedStart->copy()->addHours(5)],
    [$autoReturnWo, $plannedStart->copy()->addHours(6), $plannedStart->copy()->addHours(8)],
] as [$workOrder, $scheduledStart, $scheduledEnd]) {
    ProductionSchedule::query()->create([
        'work_order_id' => $workOrder->id,
        'machine_id' => $machine->id,
        'mold_id' => $mold->id,
        'scheduled_start' => $scheduledStart,
        'scheduled_end' => $scheduledEnd,
        'priority_order' => 1,
        'status' => ProductionScheduleStatus::Pending,
        'is_confirmed' => false,
    ]);
}

$fixture = [
    'database' => $database,
    'run_id' => $runId,
    'product_id' => $product->hash_id,
    'product_code' => $product->part_number,
    'raw_item_id' => $rawItem->hash_id,
    'raw_item_code' => $rawItem->code,
    'finished_item_id' => $finishedItem->hash_id,
    'machine_id' => $machine->hash_id,
    'machine_code' => $machine->machine_code,
    'mold_id' => $mold->hash_id,
    'mold_code' => $mold->mold_code,
    'raw_location_id' => $rawLocation->hash_id,
    'fg_location_id' => $fgLocation->hash_id,
    'defect_type_id' => $defect->hash_id,
    'sales_order_id' => $salesOrder->hash_id,
    'sales_order_number' => $salesOrder->so_number,
    'recovery_work_order_id' => $recoveryWo->hash_id,
    'recovery_work_order_number' => $recoveryWo->wo_number,
    'main_work_order_id' => $mainWo->hash_id,
    'main_work_order_number' => $mainWo->wo_number,
    'auto_return_work_order_id' => $autoReturnWo->hash_id,
    'auto_return_work_order_number' => $autoReturnWo->wo_number,
    'qc_checker_email' => $qcChecker->email,
    'output_operator_id' => $manager->employee?->hash_id,
    'shift_name' => $shift->name,
    'material_lot_number' => 'PWO-LOT-'.$suffix,
];

$path = getenv('PRODUCTION_WORKORDERS_FIXTURE_PATH') ?: '/tmp/production-workorders-fixture-'.$runId.'.json';
file_put_contents($path, json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'PRODUCTION_WORKORDERS_FIXTURE='.base64_encode(json_encode($fixture, JSON_THROW_ON_ERROR)).PHP_EOL;
