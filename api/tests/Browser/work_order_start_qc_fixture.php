<?php

declare(strict_types=1);

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\ShiftAssignmentService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\BomItem;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Quality\Services\InspectionSpecService;
use Illuminate\Support\Facades\DB;

$tag = getenv('E2E_START_TAG');
if (! $tag || ! preg_match('/^[a-z0-9-]{1,6}$/D', $tag)) {
    throw new RuntimeException('A unique E2E_START_TAG is required.');
}
if (app()->environment() !== 'local' || DB::connection()->getDatabaseName() !== 'ogami_test_audit_20261002') {
    throw new RuntimeException('Refusing work-order start fixtures outside the local audit database.');
}

$fixture = DB::transaction(function () use ($tag): array {
    $production = User::query()->where('email', 'production@ogami.test')->firstOrFail();
    $department = Department::firstOrCreate(['code' => 'PROD'], ['name' => 'Production', 'is_active' => true]);
    if (! $department->is_active) {
        $department->update(['is_active' => true]);
    }
    $worker = Employee::factory()->create([
        'first_name' => 'Start QC', 'last_name' => "Worker {$tag}", 'department_id' => $department->id,
    ]);
    $workerAccount = User::factory()->withRole('employee')->create([
        'name' => "Start QC Worker {$tag}", 'email' => "start-qc-worker-{$tag}@ogami.test",
        'employee_id' => $worker->id, 'password' => 'password', 'must_change_password' => false,
        'is_active' => true,
    ]);
    $workerShift = Shift::create([
        'name' => "Start QC full-day shift {$tag}", 'start_time' => '00:00:00', 'end_time' => '23:59:59',
        'break_minutes' => 0, 'is_night_shift' => false, 'is_extended' => false, 'is_active' => true,
    ]);
    app(ShiftAssignmentService::class)->assignToEmployee(
        $worker->id, $workerShift->id, now()->subDay()->toDateString(), now()->addDays(14)->toDateString(), [1, 2, 3, 4, 5, 6, 7],
    );
    $workDate = now()->timezone(config('app.timezone'))->startOfDay();
    Attendance::create([
        'employee_id' => $worker->id,
        'date' => $workDate->toDateString(),
        'shift_id' => $workerShift->id,
        'time_in' => $workDate,
        'time_out' => null,
        'status' => 'present',
        'is_manual_entry' => true,
        'remarks' => "Isolated start-QC browser fixture {$tag}",
    ]);
    $checker = User::factory()->withRole('qc_inspector')->create([
        'name' => "Start QC checker {$tag}",
        'email' => "start-qc-checker-{$tag}@ogami.test",
    ]);
    $product = Product::factory()->create([
        'part_number' => "START-QC-{$tag}",
        'name' => "Work-order start QC test {$tag}",
    ]);
    $warehouse = Warehouse::factory()->create([
        'code' => "START-WH-{$tag}",
        'name' => "Start QC warehouse {$tag}",
    ]);
    $parameters = [
        [
            'parameter_name' => 'Surface condition',
            'parameter_type' => 'visual',
            'acceptance_criterion' => 'No visible cracks, flash, or contamination.',
            'is_critical' => false,
        ],
        [
            'parameter_name' => 'Color',
            'parameter_type' => 'visual',
            'acceptance_criterion' => 'Matches the approved product sample.',
            'is_critical' => false,
        ],
        [
            'parameter_name' => 'Packaging',
            'parameter_type' => 'visual',
            'acceptance_criterion' => 'Packaging is intact and identifies the product lot.',
            'is_critical' => false,
        ],
    ];
    $parameters[] = [
        'parameter_name' => 'Outer diameter',
        'parameter_type' => 'dimensional',
        'unit_of_measure' => 'mm',
        'nominal_value' => '10.0000',
        'tolerance_min' => '9.9000',
        'tolerance_max' => '10.1000',
        'is_critical' => true,
    ];
    app(InspectionSpecService::class)->upsertForProduct($product->id, $parameters, $production->id);

    $machine = Machine::factory()->create([
        'machine_code' => "START-{$tag}",
        'status' => 'idle',
        'current_work_order_id' => null,
    ]);
    $mold = Mold::create([
        'mold_code' => "START-{$tag}",
        'name' => "Start QC mold {$tag}",
        'product_id' => $product->id,
        'cavity_count' => 1,
        'cycle_time_seconds' => 30,
        'output_rate_per_hour' => 120,
        'setup_time_minutes' => 5,
        'current_shot_count' => 0,
        'max_shots_before_maintenance' => 100000,
        'lifetime_max_shots' => 1000000,
        'status' => 'available',
    ]);
    $mold->compatibleMachines()->attach($machine->id);

    $rawItem = Item::factory()->create([
        'code' => "START-RM-{$tag}",
        'name' => "Start QC resin {$tag}",
        'unit_of_measure' => 'kg',
        'item_type' => 'raw_material',
        'standard_cost' => '5.0000',
        'is_active' => true,
    ]);
    $rawZone = WarehouseZone::factory()->create([
        'warehouse_id' => $warehouse->id,
        'name' => "Start QC raw materials {$tag}",
        'code' => "SRM-{$tag}",
        'zone_type' => 'raw_materials',
    ]);
    $rawLocation = WarehouseLocation::factory()->create([
        'zone_id' => $rawZone->id,
        'code' => "START-RM-{$tag}",
    ]);
    StockLevel::create([
        'item_id' => $rawItem->id,
        'location_id' => $rawLocation->id,
        'quantity' => '100.000',
        'reserved_quantity' => '0.000',
        'weighted_avg_cost' => '5.0000',
    ]);
    $bom = Bom::create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);
    BomItem::create([
        'bom_id' => $bom->id,
        'item_id' => $rawItem->id,
        'quantity_per_unit' => '1.0000',
        'unit' => 'kg',
        'waste_factor' => '0.00',
    ]);

    $finishedItem = Item::factory()->create([
        'code' => $product->part_number,
        'name' => $product->name,
        'unit_of_measure' => 'pcs',
        'item_type' => 'finished_good',
        'standard_cost' => '5.0000',
        'is_active' => true,
    ]);
    $finishedZone = WarehouseZone::factory()->create([
        'warehouse_id' => $warehouse->id,
        'name' => "Start QC finished goods {$tag}",
        'code' => "SFG-{$tag}",
        'zone_type' => 'finished_goods',
    ]);
    $finishedLocation = WarehouseLocation::factory()->create([
        'zone_id' => $finishedZone->id,
        'code' => "START-FG-{$tag}",
    ]);

    return [
        'tag' => $tag,
        'checker_email' => $checker->email,
        'worker_email' => $workerAccount->email,
        'product' => $product->hash_id,
        'machine' => $machine->hash_id,
        'mold' => $mold->hash_id,
        'raw_item' => $rawItem->hash_id,
        'raw_location' => $rawLocation->hash_id,
        'finished_item' => $finishedItem->hash_id,
        'finished_location' => $finishedLocation->hash_id,
    ];
});

$json = json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
file_put_contents(storage_path("app/work-order-start-qc-e2e-{$tag}.json"), $json);
echo json_encode($fixture, JSON_THROW_ON_ERROR), PHP_EOL;
