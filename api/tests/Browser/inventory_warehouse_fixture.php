<?php

declare(strict_types=1);

// Run through Artisan Tinker only against the dedicated Inventory browser DB.
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemUomConversion;
use App\Modules\Inventory\Models\MaterialReservation;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderMaterial;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionEntityType;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionResultAuthor;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Common\Services\SettingsService;
use Illuminate\Support\Facades\Auth;

$database = (string) config('database.connections.pgsql.database');
$expectedDatabase = getenv('INVENTORY_BROWSER_DB') ?: 'ogami_test_inventory_browser_audit_0925';
if ($database !== $expectedDatabase || ! str_starts_with($database, 'ogami_test_inventory_browser_')) {
    throw new RuntimeException('Inventory browser fixtures require the dedicated '.$expectedDatabase.' database; got '.$database);
}

foreach ([
    Database\Seeders\RolePermissionSeeder::class,
    Database\Seeders\SettingsSeeder::class,
    Database\Seeders\ChartOfAccountsSeeder::class,
    Database\Seeders\DepartmentSeeder::class,
    Database\Seeders\PositionSeeder::class,
    Database\Seeders\DemoAccountSeeder::class,
    Database\Seeders\WorkflowSeeder::class,
    Database\Seeders\UomSeeder::class,
    Database\Seeders\InventoryItemSeeder::class,
] as $seeder) {
    (new $seeder)->run();
}
app(SettingsService::class)->set('modules.accounting', true, 'modules');

$warehouseUser = User::query()->where('email', 'warehouse@ogami.test')->firstOrFail();
$warehouseChecker = User::factory()->create([
    'name' => 'Inventory Audit Warehouse Checker',
    'email' => 'warehouse-checker@ogami.test',
    'role_id' => $warehouseUser->role_id,
]);
$warehouseFinisher = User::factory()->create([
    'name' => 'Inventory Audit Warehouse Finisher',
    'email' => 'warehouse-finisher@ogami.test',
    'role_id' => $warehouseUser->role_id,
]);
$purchaser = User::query()->where('email', 'purchasing@ogami.test')->firstOrFail();
$vendor = Vendor::factory()->create(['name' => 'Inventory audit supplier']);

$warehouse = Warehouse::factory()->create(['code' => 'AUD-WH', 'name' => 'Inventory Audit Warehouse', 'is_active' => true]);
$raw = WarehouseZone::factory()->create([
    'warehouse_id' => $warehouse->id,
    'code' => 'RAW',
    'name' => 'Raw Materials',
    'zone_type' => 'raw_materials',
]);
$other = WarehouseZone::factory()->create([
    'warehouse_id' => $warehouse->id,
    'code' => 'STG',
    'name' => 'Staging',
    'zone_type' => 'staging',
]);
$quarantineZone = WarehouseZone::factory()->create([
    'warehouse_id' => $warehouse->id,
    'code' => 'QTN',
    'name' => 'Quarantine',
    'zone_type' => 'quarantine',
]);
$scrapZone = WarehouseZone::factory()->create([
    'warehouse_id' => $warehouse->id,
    'code' => 'SCR',
    'name' => 'Scrap',
    'zone_type' => 'scrap',
]);

$receiptLocation = WarehouseLocation::factory()->create([
    'zone_id' => $raw->id,
    'code' => 'AUD-RECEIPT',
    'is_active' => true,
    'is_blocked' => false,
]);
$transferLocation = WarehouseLocation::factory()->create([
    'zone_id' => $other->id,
    'code' => 'AUD-TRANSFER',
    'is_active' => true,
    'is_blocked' => false,
]);
$quarantineLocation = WarehouseLocation::factory()->create([
    'zone_id' => $quarantineZone->id,
    'code' => 'AUD-QUARANTINE',
    'is_active' => true,
    'is_blocked' => false,
]);
$scrapLocation = WarehouseLocation::factory()->create([
    'zone_id' => $scrapZone->id,
    'code' => 'AUD-SCRAP',
    'is_active' => true,
    'is_blocked' => false,
]);
$blockedLocation = WarehouseLocation::factory()->create([
    'zone_id' => $raw->id,
    'code' => 'AUD-BLOCKED',
    'is_active' => true,
    'is_blocked' => true,
]);
$inactiveLocation = WarehouseLocation::factory()->create([
    'zone_id' => $raw->id,
    'code' => 'AUD-INACTIVE',
    'is_active' => false,
    'is_blocked' => false,
]);
$inactiveWarehouse = Warehouse::factory()->create([
    'code' => 'AUD-OFF',
    'name' => 'Inactive Audit Warehouse',
    'is_active' => false,
]);
$inactiveZone = WarehouseZone::factory()->create([
    'warehouse_id' => $inactiveWarehouse->id,
    'code' => 'OFF',
    'name' => 'Inactive Warehouse Zone',
    'zone_type' => 'raw_materials',
]);
$inactiveWarehouseLocation = WarehouseLocation::factory()->create([
    'zone_id' => $inactiveZone->id,
    'code' => 'AUD-OFF-BIN',
    'is_active' => true,
    'is_blocked' => false,
]);

// Put the work-order issue item after the first 100-code page so the browser
// check proves the form loads complete active-item pagination.
foreach (range(1, 101) as $index) {
    Item::factory()->create([
        'code' => sprintf('AAA-AUD-ITEM-%03d', $index),
        'name' => sprintf('Page-one audit picker item %03d', $index),
        'is_active' => true,
    ]);
}

$kg = Uom::query()->firstOrCreate(['code' => 'KG'], ['name' => 'Kilogram']);
$bag = Uom::query()->firstOrCreate(['code' => 'BAG'], ['name' => 'Bag']);
$receiptItem = Item::factory()->create([
    'code' => 'AUD-RESIN',
    'name' => 'Inventory Audit Resin',
    'unit_of_measure' => 'KG',
    'item_type' => 'raw_material',
    'standard_cost' => '5.00',
    'reorder_point' => '0',
    'safety_stock' => '0',
    'is_active' => true,
]);
ItemUomConversion::query()->create([
    'item_id' => $receiptItem->id,
    'from_uom_id' => $bag->id,
    'to_uom_id' => $kg->id,
    'factor' => '5.000000',
]);

$po = PurchaseOrder::factory()->create([
    'po_number' => 'PO-AUD-INV-0925',
    'vendor_id' => $vendor->id,
    'created_by' => $purchaser->id,
    'status' => PurchaseOrderStatus::Sent->value,
]);
$poLine = PurchaseOrderItem::query()->create([
    'purchase_order_id' => $po->id,
    'item_id' => $receiptItem->id,
    'description' => $receiptItem->name,
    'quantity' => '2.000',
    'unit' => 'BAG',
    'unit_price' => '25.00',
    'total' => '50.00',
    'quantity_received' => '0.000',
    'quantity_accepted' => '0.000',
]);
Auth::login($warehouseUser);
$draftGrn = app(GrnService::class)->createDraftForPo($po, $warehouseUser);
if (! $draftGrn) {
    throw new RuntimeException('Unable to stage expected GRN fixture.');
}

$reservedItem = Item::factory()->create([
    'code' => 'AUD-RESERVED',
    'name' => 'Inventory Audit Reserved Material',
    'unit_of_measure' => 'KG',
    'item_type' => 'raw_material',
    'standard_cost' => '12.50',
    'reorder_point' => '0',
    'safety_stock' => '0',
    'is_active' => true,
]);
$reservationLocation = WarehouseLocation::factory()->create([
    'zone_id' => $raw->id,
    'code' => 'AUD-RESERVED-BIN',
    'is_active' => true,
    'is_blocked' => false,
]);
$availableLocation = WarehouseLocation::factory()->create([
    'zone_id' => $raw->id,
    'code' => 'AUD-FREE-BIN',
    'is_active' => true,
    'is_blocked' => false,
]);
app(StockMovementService::class)->move(new StockMovementInput(
    type: StockMovementType::Opening,
    itemId: $reservedItem->id,
    quantity: '20.000',
    toLocationId: $reservationLocation->id,
    unitCost: '12.5000',
    remarks: 'Inventory audit browser fixture opening balance',
    createdBy: $warehouseUser->id,
));
app(StockMovementService::class)->move(new StockMovementInput(
    type: StockMovementType::Opening,
    itemId: $reservedItem->id,
    quantity: '6.000',
    toLocationId: $availableLocation->id,
    unitCost: '12.5000',
    remarks: 'Inventory audit browser fixture available balance',
    createdBy: $warehouseUser->id,
));
$product = App\Modules\CRM\Models\Product::query()->create([
    'part_number' => 'AUD-WO-PART',
    'name' => 'Inventory audit work-order part',
]);
$workOrder = WorkOrder::factory()->create([
    'wo_number' => 'WO-AUD-INV-0925',
    'product_id' => $product->id,
    'created_by' => $warehouseUser->id,
    'status' => 'confirmed',
]);
WorkOrderMaterial::query()->create([
    'work_order_id' => $workOrder->id,
    'item_id' => $reservedItem->id,
    'bom_quantity' => '20.000',
    'standard_unit_cost' => '12.5000',
    'standard_cost' => '250.00',
    'actual_quantity_issued' => '0.000',
    'actual_cost' => '0.00',
    'cost_variance' => '0.00',
    'variance' => '0.000',
]);
app(StockMovementService::class)->reserve($reservedItem->id, $reservationLocation->id, '20.000');
$reservation = MaterialReservation::query()->create([
    'item_id' => $reservedItem->id,
    'work_order_id' => $workOrder->id,
    'location_id' => $reservationLocation->id,
    'quantity' => '20.000',
    'status' => 'reserved',
    'reserved_at' => now(),
]);

// Explicit starting state for the stock-adjustment/QC-hold browser test:
// 100 finished units exist, but outgoing inspection released only 98.
$qualityHoldItem = Item::factory()->create([
    'code' => 'AUD-QC-HOLD',
    'name' => 'Inventory audit QC-held finished good',
    'unit_of_measure' => 'pcs',
    'item_type' => 'finished_good',
    'standard_cost' => '5.00',
    'reorder_point' => '0',
    'safety_stock' => '0',
    'is_active' => true,
]);
$qualityZone = WarehouseZone::factory()->create([
    'warehouse_id' => $warehouse->id,
    'code' => 'QC-FG',
    'name' => 'QC-held finished goods',
    'zone_type' => 'finished_goods',
]);
$qualityLocation = WarehouseLocation::factory()->create([
    'zone_id' => $qualityZone->id,
    'code' => 'AUD-QC-HOLD-BIN',
    'is_active' => true,
    'is_blocked' => false,
]);
$qualityProduct = App\Modules\CRM\Models\Product::query()->create([
    'part_number' => 'AUD-QC-HOLD-PART',
    'name' => 'Inventory audit QC-held part',
]);
$qualityMaker = User::factory()->create();
$qualityReviewer = User::factory()->create();
$qualityWorkOrder = WorkOrder::factory()->create([
    'wo_number' => 'WO-AUD-QC-HOLD',
    'product_id' => $qualityProduct->id,
    'created_by' => $qualityMaker->id,
    'status' => 'closed',
    'quantity_target' => 100,
    'quantity_produced' => 100,
    'quantity_good' => 100,
]);
$processInspection = Inspection::query()->create([
    'inspection_number' => 'QC-AUD-HOLD-IP',
    'stage' => InspectionStage::InProcess->value,
    'status' => InspectionStatus::Passed->value,
    'product_id' => $qualityProduct->id,
    'entity_type' => InspectionEntityType::WorkOrder->value,
    'entity_id' => $qualityWorkOrder->id,
    'batch_quantity' => 100,
    'accepted_quantity' => 100,
    'sample_size' => 20,
    'inspector_id' => $qualityMaker->id,
    'reviewed_by' => $qualityReviewer->id,
    'reviewed_at' => now(),
    'completed_at' => now(),
]);
InspectionResultAuthor::query()->create([
    'inspection_id' => $processInspection->id,
    'user_id' => $qualityMaker->id,
]);
$qualityOutput = WorkOrderOutput::query()->create([
    'work_order_id' => $qualityWorkOrder->id,
    'recorded_by' => $qualityMaker->id,
    'recorded_at' => now(),
    'good_count' => 100,
    'reject_count' => 0,
    'batch_code' => 'AUD-QC-HOLD-LOT',
]);
Inspection::query()->create([
    'inspection_number' => 'QC-AUD-HOLD-OUT',
    'stage' => InspectionStage::Outgoing->value,
    'status' => InspectionStatus::Passed->value,
    'product_id' => $qualityProduct->id,
    'entity_type' => InspectionEntityType::WorkOrder->value,
    'entity_id' => $qualityWorkOrder->id,
    'work_order_output_id' => $qualityOutput->id,
    'batch_quantity' => 100,
    'accepted_quantity' => 98,
    'sample_size' => 20,
    'inspector_id' => $qualityMaker->id,
    'reviewed_by' => $qualityReviewer->id,
    'reviewed_at' => now(),
    'completed_at' => now(),
]);
$qualityReceipt = App\Modules\Inventory\Models\StockMovement::query()->create([
    'movement_type' => 'production_receipt',
    'item_id' => $qualityHoldItem->id,
    'quantity' => '100.000',
    'unit_cost' => '5.0000',
    'total_cost' => '500.00',
    'to_location_id' => $qualityLocation->id,
    'reference_type' => 'work_order_output',
    'reference_id' => $qualityOutput->id,
    'created_by' => $qualityMaker->id,
    'lot_number' => 'AUD-QC-HOLD-LOT',
]);
$qualityOutput->forceFill(['production_receipt_movement_id' => $qualityReceipt->id])->save();
App\Modules\Inventory\Models\StockLevel::query()->create([
    'item_id' => $qualityHoldItem->id,
    'location_id' => $qualityLocation->id,
    'quantity' => '100.000',
    'reserved_quantity' => '0.000',
    'weighted_avg_cost' => '5.0000',
]);

app(SettingsService::class)->set('inventory.stock_count.variance_tolerance_pct', 5, 'inventory');
// A normal inventory adjustment above this value must enter the existing
// Finance approval path; stock-count variance approval remains a separate
// Warehouse checker step.
app(SettingsService::class)->set('inventory.adjustment_approval_threshold', 5, 'inventory');

$fixture = [
    'database' => $database,
    'po' => $po->hash_id,
    'po_line' => $poLine->hash_id,
    'grn' => $draftGrn->hash_id,
    'receipt_item' => $receiptItem->hash_id,
    'receipt_location' => $receiptLocation->hash_id,
    'transfer_location' => $transferLocation->hash_id,
    'quarantine_location' => $quarantineLocation->hash_id,
    'scrap_location' => $scrapLocation->hash_id,
    'blocked_location' => $blockedLocation->hash_id,
    'inactive_location' => $inactiveLocation->hash_id,
    'inactive_warehouse_location' => $inactiveWarehouseLocation->hash_id,
    'reserved_item' => $reservedItem->hash_id,
    'reservation_location' => $reservationLocation->hash_id,
    'available_location' => $availableLocation->hash_id,
    'work_order' => $workOrder->hash_id,
    'reservation' => $reservation->hash_id,
    'quality_hold_item' => $qualityHoldItem->hash_id,
    'quality_hold_location' => $qualityLocation->hash_id,
    'quality_hold_on_hand' => '100.000',
    'quality_hold_quantity' => '2.000',
    'count_zone' => $raw->hash_id,
    'warehouse_checker' => $warehouseChecker->email,
    'warehouse_finisher' => $warehouseFinisher->email,
];

$json = json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
file_put_contents(getenv('INVENTORY_BROWSER_FIXTURE') ?: '/tmp/inventory-warehouse-browser-fixture.json', $json);
echo "INVENTORY_WAREHOUSE_FIXTURE=".base64_encode($json).PHP_EOL;
