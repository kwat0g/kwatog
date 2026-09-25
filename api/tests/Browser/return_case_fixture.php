<?php

declare(strict_types=1);

// Run through Artisan Tinker against a dedicated browser-test database only.
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\SupplyChain\Models\Delivery;
use Illuminate\Support\Facades\Auth;

if (! str_starts_with(config('database.connections.pgsql.database'), 'ogami_test_return_browser_')) {
    throw new RuntimeException('This fixture requires its dedicated browser-test database.');
}

foreach ([
    Database\Seeders\RolePermissionSeeder::class, Database\Seeders\SettingsSeeder::class,
    Database\Seeders\ChartOfAccountsSeeder::class, Database\Seeders\DepartmentSeeder::class,
    Database\Seeders\PositionSeeder::class, Database\Seeders\DemoAccountSeeder::class,
    Database\Seeders\WorkflowSeeder::class,
] as $seeder) {
    (new $seeder)->run();
}
$buyer = User::where('email', 'purchasing@ogami.test')->firstOrFail();
Auth::login($buyer);
$vendor = Vendor::factory()->create(['name' => 'Return browser supplier']);
$item = Item::factory()->create(['code' => 'RM-BROWSER-RESIN', 'name' => 'Browser test resin', 'unit_of_measure' => 'KG', 'standard_cost' => '20']);
$location = WarehouseLocation::factory()->create(['is_active' => true, 'is_blocked' => false]);
$po = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id, 'created_by' => $buyer->id, 'po_number' => 'PO-RETURN-BROWSER', 'status' => 'sent']);
$poLine = PurchaseOrderItem::create([
    'purchase_order_id' => $po->id, 'item_id' => $item->id, 'description' => $item->name,
    'quantity' => '20', 'unit' => 'KG', 'unit_price' => '20', 'total' => '400',
    'quantity_received' => '0', 'quantity_accepted' => '0',
]);
SupplierPortalUser::create(['vendor_id' => $vendor->id, 'name' => 'Supplier test contact', 'email' => 'supplier.return@ogami.test',
    'password' => 'password', 'is_active' => true, 'must_change_password' => false, 'password_changed_at' => now()]);
$customer = Customer::factory()->create(['name' => 'Return browser customer']);
CustomerPortalUser::create(['customer_id' => $customer->id, 'name' => 'Customer test contact', 'email' => 'customer.return@ogami.test',
    'password' => 'password', 'is_active' => true, 'must_change_password' => false, 'password_changed_at' => now()]);
$product = Product::create(['part_number' => 'FG-BROWSER-RETURN', 'name' => 'Browser test part']);
Item::factory()->create(['code' => $product->part_number, 'name' => $product->name, 'item_type' => 'finished_good', 'unit_of_measure' => 'pcs']);
$spec = InspectionSpec::create(['product_id' => $product->id, 'version' => 1, 'is_active' => true, 'created_by' => $buyer->id]);
InspectionSpecItem::create(['inspection_spec_id' => $spec->id, 'parameter_name' => 'Return condition', 'parameter_type' => 'visual', 'is_critical' => true, 'sort_order' => 1]);
$quarantineZone = WarehouseZone::factory()->create(['zone_type' => 'quarantine']);
$quarantine = WarehouseLocation::factory()->create(['zone_id' => $quarantineZone->id, 'is_active' => true, 'is_blocked' => false]);
$order = SalesOrder::factory()->create(['customer_id' => $customer->id, 'created_by' => $buyer->id, 'status' => 'partially_delivered']);
$orderLine = SalesOrderItem::factory()->create(['sales_order_id' => $order->id, 'product_id' => $product->id, 'quantity' => '100', 'quantity_delivered' => '100', 'unit_price' => '25']);
$delivery = Delivery::create(['delivery_number' => 'DEL-RETURN-BROWSER', 'sales_order_id' => $order->id, 'status' => 'delivered',
    'scheduled_date' => now()->toDateString(), 'delivered_at' => now(), 'created_by' => $buyer->id]);
$delivery->items()->create(['sales_order_item_id' => $orderLine->id, 'quantity' => '100', 'unit_price' => '25']);

$fixture = ['po' => $po->hash_id, 'po_line' => $poLine->hash_id, 'item' => $item->hash_id,
    'location' => $location->hash_id, 'quarantine' => $quarantine->hash_id, 'delivery' => $delivery->hash_id];
file_put_contents('/tmp/return-case-role-fixture.json', json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "Return-case browser fixtures ready.\n";
