<?php

declare(strict_types=1);

// Order-to-cash browser prerequisites: master data only. The browser creates
// every sales order, work order, inspection, delivery, invoice and payment.
// Run through `artisan tinker` against a freshly migrated database whose name
// starts with ogami_test_o2c_browser_ (scripts/o2c-headless.cjs does this).
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Services\BomService;
use App\Modules\Production\Models\DefectType;
use App\Modules\Quality\Services\InspectionSpecService;
use App\Modules\SupplyChain\Models\Vehicle;
use Illuminate\Support\Facades\DB;

$database = (string) config('database.connections.pgsql.database');
if (! str_starts_with($database, 'ogami_test_o2c_browser_') || $database !== getenv('O2C_BROWSER_DB')) {
    throw new RuntimeException('Select a dedicated ogami_test_o2c_browser_* database explicitly.');
}
config(['cache.default' => 'array']);
$run = strtoupper(preg_replace('/[^a-z0-9]/i', '', getenv('O2C_RUN_ID') ?: ''));
if (strlen($run) < 6 || strlen($run) > 16) throw new RuntimeException('Use a unique 6–16 character O2C_RUN_ID.');
if (Product::where('part_number', 'O2C-'.$run)->exists()) throw new RuntimeException('Use a fresh database and run identifier.');

foreach ([
    Database\Seeders\RolePermissionSeeder::class, Database\Seeders\SettingsSeeder::class,
    Database\Seeders\ChartOfAccountsSeeder::class, Database\Seeders\DepartmentSeeder::class,
    Database\Seeders\PositionSeeder::class, Database\Seeders\DemoAccountSeeder::class,
    Database\Seeders\WorkflowSeeder::class, Database\Seeders\WarehouseSeeder::class,
    Database\Seeders\DefectTypeSeeder::class,
] as $seeder) (new $seeder)->run();
foreach (['crm', 'mrp', 'production', 'quality', 'inventory', 'supply_chain', 'accounting', 'b2b_portals'] as $module) {
    app(SettingsService::class)->set('modules.'.$module, true, 'modules');
}

$admin = User::where('email', 'admin@ogami.test')->firstOrFail();
auth()->login($admin);

$customer = Customer::factory()->create([
    'name' => 'O2C customer '.$run, 'email' => 'buyer.o2c@ogami.test',
    'credit_limit' => '5000000.00', 'payment_terms_days' => 30, 'is_active' => true,
]);
$portal = CustomerPortalUser::create([
    'customer_id' => $customer->id, 'name' => 'O2C receiving '.$run, 'email' => 'customer.o2c@ogami.test',
    'password' => 'password', 'is_active' => true, 'must_change_password' => false, 'password_changed_at' => now(),
]);

$product = Product::factory()->create([
    'part_number' => 'O2C-'.$run, 'name' => 'O2C wiper bushing', 'unit_of_measure' => 'pcs',
    'standard_cost' => '5.00', 'is_active' => true,
]);
Item::factory()->create([
    'code' => $product->part_number, 'name' => $product->name, 'item_type' => 'finished_good',
    'unit_of_measure' => 'pcs', 'is_active' => true,
]);
$resin = Item::factory()->create([
    'code' => 'RM-'.$run, 'name' => 'O2C resin', 'item_type' => 'raw_material', 'unit_of_measure' => 'kg',
    'standard_cost' => '100.00', 'lead_time_days' => 3, 'is_active' => true,
]);
$rawLocation = DB::table('warehouse_locations as l')->join('warehouse_zones as z', 'z.id', '=', 'l.zone_id')
    ->where('z.zone_type', 'raw_materials')->where('l.is_active', true)->orderBy('l.id')->value('l.id');
app(StockMovementService::class)->move(new StockMovementInput(
    type: StockMovementType::Opening, itemId: $resin->id, quantity: '500', toLocationId: (int) $rawLocation,
    unitCost: '100.00', remarks: 'O2C browser opening stock', createdBy: $admin->id,
));
app(BomService::class)->create($product->id, [
    ['item_id' => $resin->id, 'quantity_per_unit' => '0.0200', 'unit' => 'kg', 'waste_factor' => '0'],
]);
app(InspectionSpecService::class)->upsertForProduct($product->id, [
    ['parameter_name' => 'Outer diameter', 'parameter_type' => 'dimensional', 'unit_of_measure' => 'mm',
        'nominal_value' => '10.000', 'tolerance_min' => '9.950', 'tolerance_max' => '10.050', 'is_critical' => true],
    ['parameter_name' => 'Flash / short shot', 'parameter_type' => 'visual', 'is_critical' => false],
], $admin->id, 'O2C browser spec');

$machine = Machine::factory()->create(['machine_code' => 'MC-'.substr($run, -6), 'name' => 'O2C press', 'status' => 'idle']);
$mold = Mold::create([
    'mold_code' => 'MD-'.substr($run, -6), 'name' => 'O2C mold', 'product_id' => $product->id, 'cavity_count' => 4,
    'cycle_time_seconds' => 30, 'output_rate_per_hour' => 480, 'setup_time_minutes' => 30,
    'max_shots_before_maintenance' => 100000, 'lifetime_max_shots' => 1000000, 'status' => 'available',
]);
DB::table('mold_machine_compatibility')->insert(['mold_id' => $mold->id, 'machine_id' => $machine->id]);
PriceAgreement::create([
    'product_id' => $product->id, 'customer_id' => $customer->id, 'price' => '12.50', 'pricing_method' => 'flat',
    'effective_from' => now()->subDay()->toDateString(), 'effective_to' => now()->addYear()->toDateString(),
]);
$vehicle = Vehicle::create(['plate_number' => 'O2C-'.substr($run, -6), 'name' => 'O2C van', 'vehicle_type' => 'van', 'status' => 'available']);

$fixture = [
    'run_id' => $run, 'database' => $database,
    'customer' => $customer->hash_id, 'product' => $product->hash_id, 'product_code' => $product->part_number,
    'vehicle' => $vehicle->hash_id, 'customer_email' => $portal->email,
    'cash_account' => app('hashids')->encode((int) DB::table('accounts')->where('code', '1020')->value('id')),
    'revenue_account' => app('hashids')->encode((int) DB::table('accounts')->where('code', '4010')->value('id')),
    'defect_type' => DefectType::query()->orderBy('id')->firstOrFail()->hash_id,
];
file_put_contents(getenv('O2C_FIXTURE_PATH') ?: '/tmp/o2c-fixture.json', json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "O2C prerequisites ready: {$run}.\n";
