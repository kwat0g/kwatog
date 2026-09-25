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
    'count_zone' => $raw->hash_id,
    'warehouse_checker' => $warehouseChecker->email,
];

$json = json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
file_put_contents(getenv('INVENTORY_BROWSER_FIXTURE') ?: '/tmp/inventory-warehouse-browser-fixture.json', $json);
echo "INVENTORY_WAREHOUSE_FIXTURE=".base64_encode($json).PHP_EOL;
