<?php

declare(strict_types=1);

$apiRoot = is_dir(__DIR__.'/../api') ? __DIR__.'/../api' : __DIR__.'/..';
require $apiRoot.'/vendor/autoload.php';
$app = require $apiRoot.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.connections.pgsql.database') !== 'ogami_test_audit_20261002') {
    throw new RuntimeException('Crew E2E fixtures require the isolated audit database.');
}

use App\Modules\Auth\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\CRM\Models\Product;
use App\Modules\Production\Models\DefectType;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Services\ShiftAssignmentService;
use App\Modules\Quality\Services\InspectionSpecService;

$tag = 'crew-'.bin2hex(random_bytes(3));
$production = Department::firstOrCreate(['code' => 'PROD'], ['name' => 'Production', 'is_active' => true]);
if (! $production->is_active) {
    $production->update(['is_active' => true]);
}
$otherDepartment = Department::create(['name' => 'E2E non-production '.$tag,
    'code' => 'E2E'.strtoupper(substr($tag, -6)), 'is_active' => true]);
$accounts = [];
foreach (['ppc' => 'ppc_head', 'manager' => 'production_manager', 'qc' => 'qc_inspector',
    'recorder' => 'employee', 'support' => 'employee', 'outsider' => 'employee'] as $name => $slug) {
    $employee = Employee::factory()->create(['first_name' => ucfirst($name), 'last_name' => $tag,
        'department_id' => in_array($name, ['recorder', 'support', 'manager'], true) ? $production->id : $otherDepartment->id]);
    $user = User::factory()->create(['role_id' => Role::where('slug', $slug)->firstOrFail()->id, 'employee_id' => $employee->id,
        'email' => $name.'-'.$tag.'@ogami.test', 'password' => 'Crew-E2E-Test-2026!', 'must_change_password' => false, 'is_active' => true]);
    $accounts[$name] = ['email' => $user->email, 'employee_id' => $employee->hash_id, 'user_id' => $user->hash_id,
        'name' => $employee->full_name];
}
$backupEmployee = Employee::factory()->create(['first_name' => 'Backup', 'last_name' => $tag, 'department_id' => $production->id]);
$backupUser = User::factory()->create(['role_id' => Role::where('slug', 'employee')->firstOrFail()->id, 'employee_id' => $backupEmployee->id,
    'email' => 'backup-'.$tag.'@ogami.test', 'password' => 'Crew-E2E-Test-2026!', 'must_change_password' => false, 'is_active' => true]);
$noShiftEmployee = Employee::factory()->create(['first_name' => 'No shift', 'last_name' => $tag, 'department_id' => $production->id]);
User::factory()->create(['role_id' => Role::where('slug', 'employee')->firstOrFail()->id, 'employee_id' => $noShiftEmployee->id,
    'email' => 'no-shift-'.$tag.'@ogami.test', 'password' => 'Crew-E2E-Test-2026!', 'must_change_password' => false, 'is_active' => true]);
$now = now()->timezone(config('app.timezone'));
$shiftStart = $now->copy()->startOfHour()->subHour();
$shiftEnd = $now->copy()->startOfHour()->addHours(5);
$nightShift = $now->hour < 8 || $now->hour >= 17;
$shift = Shift::create(['name' => ($nightShift ? 'HR night shift ' : 'HR day shift ').$tag,
    'start_time' => $shiftStart->format('H:i:s'), 'end_time' => $shiftEnd->format('H:i:s'),
    'break_minutes' => 0, 'is_night_shift' => $nightShift, 'is_extended' => ! $nightShift && $shiftEnd->hour >= 17, 'is_active' => true]);
foreach (['recorder', 'support', 'manager'] as $name) {
    app(ShiftAssignmentService::class)->assignToEmployee(Employee::decodeHash($accounts[$name]['employee_id']), $shift->id, $shiftStart->toDateString(), null, [1, 2, 3, 4, 5, 6, 7]);
}
app(ShiftAssignmentService::class)->assignToEmployee($backupEmployee->id, $shift->id, $shiftStart->toDateString(), null, [1, 2, 3, 4, 5, 6, 7]);
foreach ([Employee::decodeHash($accounts['recorder']['employee_id']), Employee::decodeHash($accounts['support']['employee_id']), $backupEmployee->id] as $employeeId) {
    Attendance::create(['employee_id' => $employeeId, 'date' => $shiftStart->toDateString(), 'shift_id' => $shift->id,
        'time_in' => $shiftStart, 'time_out' => null, 'status' => 'present', 'is_manual_entry' => true,
        'remarks' => 'Disposable crew acceptance fixture']);
}
$product = Product::factory()->create(['part_number' => strtoupper($tag), 'name' => 'Crew assignment test product', 'unit_of_measure' => 'pcs']);
$managerId = User::decodeHash($accounts['manager']['user_id']);
app(InspectionSpecService::class)->upsertForProduct($product->id, [['parameter_name' => 'Surface condition', 'parameter_type' => 'visual', 'is_critical' => false]], $managerId);
$machine = \App\Modules\MRP\Models\Machine::factory()->create(['machine_code' => strtoupper($tag.'-A'), 'status' => 'idle', 'current_work_order_id' => null]);
$futureMachine = \App\Modules\MRP\Models\Machine::factory()->create(['machine_code' => strtoupper($tag.'-B'), 'status' => 'idle', 'current_work_order_id' => null]);
$overlapMachine = \App\Modules\MRP\Models\Machine::factory()->create(['machine_code' => strtoupper($tag.'-C'), 'status' => 'idle', 'current_work_order_id' => null]);
$overlapMold = \App\Modules\MRP\Models\Mold::create(['mold_code' => strtoupper($tag.'-B'), 'name' => 'Crew E2E overlap mold',
    'product_id' => $product->id, 'status' => 'available', 'cavity_count' => 1, 'cycle_time_seconds' => 30, 'output_rate_per_hour' => 100, 'setup_time_minutes' => 0,
    'current_shot_count' => 0, 'max_shots_before_maintenance' => 100000, 'lifetime_max_shots' => 1000000]);
$overlapMold->compatibleMachines()->syncWithoutDetaching([$overlapMachine->id]);
$mold = \App\Modules\MRP\Models\Mold::create(['mold_code' => strtoupper($tag), 'name' => 'Crew E2E mold',
    'product_id' => $product->id, 'status' => 'available', 'cavity_count' => 1, 'cycle_time_seconds' => 30, 'output_rate_per_hour' => 100, 'setup_time_minutes' => 0,
    'current_shot_count' => 0, 'max_shots_before_maintenance' => 100000, 'lifetime_max_shots' => 1000000]);
$mold->compatibleMachines()->syncWithoutDetaching([$machine->id, $futureMachine->id]);
$currentStart = $now->copy()->startOfMinute()->subMinute();
$currentEnd = $now->copy()->startOfMinute()->addHours(2);
$futureStart = $currentEnd->copy()->addMinutes(30);
$futureEnd = $futureStart->copy()->addHours(1);
$prototype = ['product_id' => $product->id, 'quantity_target' => 50, 'work_order_class' => 'prototype',
    'exception_reason' => 'Disposable crew acceptance fixture', 'exception_authorized_by' => $managerId, 'minimum_workers' => 2];
$wo = WorkOrder::factory()->create($prototype + ['status' => 'confirmed',
    'machine_id' => $machine->id, 'mold_id' => $mold->id, 'created_by' => $managerId,
    'planned_start' => $currentStart, 'planned_end' => $currentEnd->copy()->addDay()]);
ProductionSchedule::create(['work_order_id' => $wo->id, 'machine_id' => $machine->id, 'mold_id' => $mold->id,
    'scheduled_start' => $currentStart, 'scheduled_end' => $currentEnd, 'priority_order' => 0, 'status' => 'confirmed',
    'is_confirmed' => true, 'confirmed_by' => $managerId, 'confirmed_at' => $now, 'reserves_capacity' => true]);
$futureWo = WorkOrder::factory()->create($prototype + ['status' => 'planned', 'machine_id' => $futureMachine->id, 'mold_id' => $mold->id,
    'created_by' => $managerId, 'planned_start' => $futureStart, 'planned_end' => $futureEnd->copy()->addDay()]);
ProductionSchedule::create(['work_order_id' => $futureWo->id, 'machine_id' => $futureMachine->id, 'mold_id' => $mold->id,
    'scheduled_start' => $futureStart, 'scheduled_end' => $futureEnd, 'priority_order' => 0, 'status' => 'pending', 'reserves_capacity' => true]);
$overlapWo = WorkOrder::factory()->create($prototype + ['status' => 'confirmed', 'machine_id' => $overlapMachine->id, 'mold_id' => $overlapMold->id,
    'created_by' => $managerId, 'planned_start' => $currentStart, 'planned_end' => $currentEnd->copy()->addDay()]);
ProductionSchedule::create(['work_order_id' => $overlapWo->id, 'machine_id' => $overlapMachine->id, 'mold_id' => $overlapMold->id,
    'scheduled_start' => $currentStart, 'scheduled_end' => $currentEnd, 'priority_order' => 0, 'status' => 'confirmed',
    'is_confirmed' => true, 'confirmed_by' => $managerId, 'confirmed_at' => $now, 'reserves_capacity' => true]);
$defect = DefectType::create(['code' => 'C'.strtoupper(substr($tag, -6)), 'name' => 'Surface defect '.$tag, 'is_active' => true]);
$data = ['tag' => $tag, 'accounts' => $accounts, 'wo' => $wo->hash_id, 'wo_number' => $wo->wo_number,
    'future_wo' => $futureWo->hash_id, 'overlap_wo' => $overlapWo->hash_id,
    'machine' => $machine->hash_id, 'future_machine' => $futureMachine->hash_id, 'mold' => $mold->hash_id,
    'backup_worker' => ['employee_id' => $backupEmployee->hash_id, 'user_id' => $backupUser->hash_id],
    'defect' => $defect->hash_id, 'shift' => $shift->name, 'night_shift' => $nightShift];
$file = storage_path('app/wo-crew-e2e.json');
file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
echo $file.PHP_EOL;
