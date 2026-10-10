<?php

declare(strict_types=1);

$apiRoot = is_dir(__DIR__.'/../api') ? __DIR__.'/../api' : __DIR__.'/..';
require $apiRoot.'/vendor/autoload.php';
$app = require $apiRoot.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.connections.pgsql.database') !== 'ogami_test_finish_first_e2e_20261008') {
    throw new RuntimeException('Finish-first fixtures require the disposable finish-first E2E database.');
}
$quantity = filter_var(getenv('E2E_FINISH_FIRST_QUANTITY') ?: '200', FILTER_VALIDATE_INT);
if ($quantity === false || $quantity < 1) {
    throw new RuntimeException('E2E_FINISH_FIRST_QUANTITY must be a positive integer.');
}
$confirmedChecks = filter_var(getenv('E2E_FINISH_FIRST_CONFIRMED_CHECKS') ?: 'false', FILTER_VALIDATE_BOOLEAN);
$scheduleHours = filter_var(getenv('E2E_FINISH_FIRST_SCHEDULE_HOURS') ?: ($quantity > 200 ? '8' : '2'), FILTER_VALIDATE_INT);
if ($scheduleHours === false || $scheduleHours < 1) {
    throw new RuntimeException('E2E_FINISH_FIRST_SCHEDULE_HOURS must be a positive integer.');
}
config([
    'inspection.finish_first_wo_enabled' => true,
    'inspection.outgoing_confirmed_checks_enabled' => $confirmedChecks,
]);
$tag = 'ff-'.bin2hex(random_bytes(3));
$department = \App\Modules\HR\Models\Department::firstOrCreate(['code' => 'PROD'], ['name' => 'Production', 'is_active' => true]);
$accounts = [];
foreach (['manager' => 'production_manager', 'recorder' => 'employee', 'qc' => 'qc_inspector', 'checker' => 'qc_inspector', 'warehouse' => 'warehouse_staff'] as $key => $role) {
    $employee = \App\Modules\HR\Models\Employee::factory()->create(['department_id' => $department->id, 'status' => 'active']);
    $user = \App\Modules\Auth\Models\User::factory()->withRole($role)->create(['employee_id' => $employee->id,
        'email' => "$key-$tag@ogami.test", 'password' => 'Finish-First-Test-2026!', 'must_change_password' => false, 'is_active' => true]);
    $accounts[$key] = ['email' => $user->email, 'id' => $user->hash_id, 'employee_id' => $employee->hash_id];
}
$manager = \App\Modules\Auth\Models\User::findOrFail(\App\Modules\Auth\Models\User::decodeHash($accounts['manager']['id']));
$recorder = \App\Modules\Auth\Models\User::findOrFail(\App\Modules\Auth\Models\User::decodeHash($accounts['recorder']['id']));
$product = \App\Modules\CRM\Models\Product::factory()->create(['part_number' => strtoupper($tag), 'name' => 'Finish-first acceptance product', 'unit_of_measure' => 'PCS', 'standard_cost' => 5]);
$specItems = $confirmedChecks ? [
    ['parameter_name' => 'Length', 'parameter_type' => 'dimensional', 'unit_of_measure' => 'mm', 'nominal_value' => '10.0000',
        'tolerance_min' => '9.9000', 'tolerance_max' => '10.1000', 'is_critical' => false],
    ['parameter_name' => 'Appearance', 'parameter_type' => 'visual', 'acceptance_criterion' => 'No visible damage', 'is_critical' => false],
] : [['parameter_name' => 'Appearance', 'parameter_type' => 'visual', 'acceptance_criterion' => 'No visible damage', 'is_critical' => false]];
app(\App\Modules\Quality\Services\InspectionSpecService::class)->upsertForProduct($product->id, $specItems, $manager->id);
$machine = \App\Modules\MRP\Models\Machine::factory()->create(['status' => 'idle', 'current_work_order_id' => null]);
$mold = \App\Modules\MRP\Models\Mold::create(['mold_code' => strtoupper($tag), 'name' => 'Finish-first acceptance mold',
    'product_id' => $product->id, 'status' => 'available', 'cavity_count' => 1, 'cycle_time_seconds' => 30, 'output_rate_per_hour' => 100,
    'setup_time_minutes' => 0, 'current_shot_count' => 0, 'max_shots_before_maintenance' => 100000, 'lifetime_max_shots' => 1000000]);
$mold->compatibleMachines()->sync([$machine->id]);
$start = now()->startOfMinute()->subMinutes(2);
$end = $start->copy()->addHours($scheduleHours);
$wo = \App\Modules\Production\Models\WorkOrder::factory()->create(['product_id' => $product->id, 'quantity_target' => $quantity,
    'status' => 'confirmed', 'machine_id' => $machine->id, 'mold_id' => $mold->id, 'created_by' => $manager->id,
    'planned_start' => $start, 'planned_end' => $end->copy()->addDay(), 'minimum_workers' => 1]);
\App\Modules\Production\Models\ProductionSchedule::create(['work_order_id' => $wo->id, 'machine_id' => $machine->id, 'mold_id' => $mold->id,
    'scheduled_start' => $start, 'scheduled_end' => $end, 'priority_order' => 0, 'status' => 'confirmed', 'is_confirmed' => true,
    'reserves_capacity' => true, 'confirmed_by' => $manager->id, 'confirmed_at' => now()]);
$shift = \App\Modules\Attendance\Models\Shift::create(['name' => $tag, 'start_time' => $start->copy()->subHour()->format('H:i:s'),
    'end_time' => $end->copy()->addHour()->format('H:i:s'), 'is_night_shift' => $end->copy()->addHour()->format('H:i:s') < $start->copy()->subHour()->format('H:i:s'), 'is_active' => true]);
app(\App\Modules\Attendance\Services\ShiftAssignmentService::class)->assignToEmployee($recorder->employee_id, $shift->id, $start->copy()->subDay()->toDateString(), null, [1,2,3,4,5,6,7]);
\App\Modules\Attendance\Models\Attendance::create(['employee_id' => $recorder->employee_id, 'date' => $start->toDateString(), 'shift_id' => $shift->id, 'time_in' => $start, 'status' => 'present']);
$crew = $wo->crews()->create(['recorder_employee_id' => $recorder->employee_id, 'assigned_by' => $manager->id, 'reason' => 'Disposable acceptance crew', 'assigned_at' => $start]);
$crew->members()->create(['employee_id' => $recorder->employee_id, 'employee_snapshot' => app(\App\Modules\Production\Services\WorkOrderAssignmentService::class)->employeeOption($recorder->employee)]);
$zone = \App\Modules\Inventory\Models\WarehouseZone::factory()->create(['zone_type' => 'finished_goods']);
$location = \App\Modules\Inventory\Models\WarehouseLocation::factory()->create(['zone_id' => $zone->id]);
$item = \App\Modules\Inventory\Models\Item::factory()->create(['code' => $product->part_number, 'item_type' => 'finished_good', 'unit_of_measure' => 'PCS']);
$raw = \App\Modules\Inventory\Models\Item::factory()->create(['item_type' => 'raw_material']);
$wo->materials()->create(['item_id' => $raw->id, 'bom_quantity' => '1.000', 'actual_quantity_issued' => '1.000']);
$issue = \App\Modules\Inventory\Models\StockMovement::create(['movement_type' => 'material_issue', 'item_id' => $raw->id,
    'quantity' => '1.000', 'unit_cost' => '1.0000', 'total_cost' => '1.00', 'from_location_id' => $location->id,
    'reference_type' => 'work_order', 'reference_id' => $wo->id, 'created_by' => $manager->id]);
\App\Modules\Inventory\Models\MaterialHandover::create(['stock_movement_id' => $issue->id, 'recorded_by' => $manager->id,
    'received_by_employee_id' => $recorder->employee_id, 'recipient_name' => $recorder->name, 'quantity' => '1.000', 'handed_over_at' => $start]);
$data = ['tag' => $tag, 'wo' => $wo->hash_id, 'quantity' => $quantity, 'accounts' => $accounts,
    'crew' => $crew->hash_id, 'item' => $item->hash_id, 'location' => $location->hash_id];
$manifestPath = getenv('E2E_FINISH_FIRST_MANIFEST_OUTPUT') ?: storage_path('app/finish-first-e2e.json');
if (! is_dir(dirname($manifestPath))) {
    mkdir(dirname($manifestPath), 0775, true);
}
file_put_contents($manifestPath, json_encode($data, JSON_PRETTY_PRINT));
echo 'Finish-first E2E fixture saved.'.PHP_EOL;
