<?php

declare(strict_types=1);

// Supplier master data only; Playwright creates and advances every document.
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use App\Modules\Quality\Services\ItemQualityPlanService;

$database = (string) config('database.connections.pgsql.database');
if (! str_starts_with($database, 'ogami_test_o2c_browser_') || $database !== getenv('O2C_BROWSER_DB')) {
    throw new RuntimeException('Supplier prerequisites require an explicitly selected disposable database.');
}
$file = getenv('O2C_FIXTURE_PATH');
$fixture = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
$admin = User::where('email', 'admin@ogami.test')->firstOrFail();
auth()->login($admin);
$vendor = Vendor::factory()->create(['name' => 'B2B component supplier', 'email' => 'supplier.company@ogami.test', 'is_active' => true, 'created_by' => $admin->id]);
$supplier = SupplierPortalUser::create(['vendor_id' => $vendor->id, 'name' => 'B2B supplier representative', 'email' => 'supplier.b2b@ogami.test',
    'password' => 'password', 'is_active' => true, 'must_change_password' => false, 'password_changed_at' => now()]);
$item = Item::factory()->create(['code' => 'SUP-'.$fixture['run_id'], 'name' => 'B2B purchased raw components', 'item_type' => 'raw_material',
    'unit_of_measure' => 'pcs', 'standard_cost' => '10.00', 'is_active' => true]);
ApprovedSupplier::create(['item_id' => $item->id, 'vendor_id' => $vendor->id, 'qualification_status' => 'approved', 'is_preferred' => true,
    'last_price' => '10.00', 'last_price_at' => now(), 'price_valid_until' => now()->addYear()->toDateString(),
    'lead_time_days' => 3, 'order_uom' => 'pcs', 'base_qty_per_order_unit' => '1.0000']);
app(ItemQualityPlanService::class)->createRevision($item, ['capture_method' => 'checklist', 'sampling_method' => 'full',
    'vendor_id' => $vendor->id, 'parameters' => [
        ['parameter_name' => 'Supplier identity and documentation', 'parameter_type' => 'visual', 'acceptance_criterion' => 'Correct part and supporting supplier documents.', 'is_critical' => true],
        ['parameter_name' => 'Visible damage', 'parameter_type' => 'visual', 'acceptance_criterion' => 'Every component is intact and free of cracks.', 'is_critical' => false],
    ]], $admin);
$fixture['supplier'] = ['id' => $vendor->hash_id, 'email' => $supplier->email, 'item' => $item->hash_id, 'item_code' => $item->code];
file_put_contents($file, json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo "B2B supplier ready.\n";
