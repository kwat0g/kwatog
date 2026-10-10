<?php

declare(strict_types=1);

use App\Modules\Auth\Models\User;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\ShiftAssignmentService;
use App\Modules\CRM\Models\Product;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\ProductRouting;
use App\Modules\Production\Models\RoutingOperation;
use App\Modules\Production\Models\WoOperation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Services\InspectionService;
use App\Modules\Quality\Services\InspectionSpecService;
use Illuminate\Support\Facades\DB;

// Run explicitly through artisan tinker. Never refresh or truncate the live database.
$tag = getenv('E2E_QC_TAG');
if (! $tag || ! preg_match('/^[a-z0-9-]{1,6}$/D', $tag)) {
    throw new RuntimeException('A unique E2E_QC_TAG is required.');
}
if (app()->environment() !== 'local' || DB::connection()->getDatabaseName() !== 'ogami_test_audit_20261002') {
    throw new RuntimeException('Refusing QC browser fixtures outside the local audit database.');
}

$manifest = DB::transaction(function () use ($tag): array {
    $maker = User::where('email', 'qc@ogami.test')->firstOrFail();
    $production = User::where('email', 'production@ogami.test')->firstOrFail();
    $department = Department::firstOrCreate(['code' => 'PROD'], ['name' => 'Production', 'is_active' => true]);
    if (! $department->is_active) {
        $department->update(['is_active' => true]);
    }
    $worker = Employee::factory()->create([
        'first_name' => 'QC E2E', 'last_name' => "Worker {$tag}", 'department_id' => $department->id,
    ]);
    $workerAccount = User::factory()->withRole('employee')->create([
        'name' => "QC E2E Worker {$tag}", 'email' => "qc-e2e-worker-{$tag}@ogami.test",
        'employee_id' => $worker->id, 'password' => 'password', 'must_change_password' => false,
        'is_active' => true,
    ]);
    $workerShift = Shift::create([
        'name' => "QC E2E full-day shift {$tag}", 'start_time' => '00:00:00', 'end_time' => '23:59:59',
        'break_minutes' => 0, 'is_night_shift' => false, 'is_extended' => false, 'is_active' => true,
    ]);
    app(ShiftAssignmentService::class)->assignToEmployee(
        $worker->id, $workerShift->id, now()->subDay()->toDateString(), now()->addDays(14)->toDateString(),
    );
    $checker = User::factory()->withRole('qc_inspector')->create([
        'name' => "QC E2E checker {$tag}", 'email' => "qc-checker-{$tag}@ogami.test",
    ]);
    $product = Product::factory()->create([
        'part_number' => "E2E-QC-{$tag}", 'name' => "100-piece QC browser test {$tag}", 'standard_cost' => 5,
    ]);
    app(InspectionSpecService::class)->upsertForProduct($product->id, array_map(
        fn (string $name): array => ['parameter_name' => $name, 'parameter_type' => 'visual', 'is_critical' => false],
        ['Surface condition', 'Color', 'Packaging'],
    ), $maker->id);
    $makeWo = fn (string $suffix, string $status): WorkOrder => WorkOrder::factory()->create([
        'wo_number' => "E2E-{$tag}-{$suffix}", 'product_id' => $product->id, 'created_by' => $production->id,
        'status' => $status, 'quantity_target' => 100, 'quantity_good' => 100, 'quantity_produced' => 100,
        'planned_start' => now()->subDay(), 'planned_end' => now()->addDay(),
    ]);
    $makePassedProcessQc = static function (WorkOrder $workOrder) use ($maker): string {
        $inspection = app(InspectionService::class)->create([
            'stage' => 'in_process', 'product_id' => $workOrder->product_id,
            'batch_quantity' => (int) $workOrder->quantity_target,
            'entity_type' => 'work_order', 'entity_id' => $workOrder->id,
            'notes' => "Explicit starting state for {$workOrder->wo_number}.",
        ], $maker);
        $inspection->measurements()->update(['is_pass' => true, 'item_result' => 'passed']);
        $inspection->forceFill([
            'status' => 'passed', 'proposed_result' => 'passed', 'recorded_result' => 'passed',
            'accepted_quantity' => (int) $workOrder->quantity_target,
            'inspected_quantity' => (int) $inspection->sample_size,
            'sample_defect_count' => 0, 'defect_count' => 0, 'completed_at' => now(),
        ])->save();
        $inspection->resultAuthors()->create(['user_id' => $maker->id]);

        return $inspection->hash_id;
    };

    $closed = $makeWo('CLOSED', 'closed');
    $closedProcessInspection = $makePassedProcessQc($closed);
    $output = WorkOrderOutput::create([
        'work_order_id' => $closed->id, 'recorded_by' => $production->id, 'recorded_at' => now(),
        'good_count' => 100, 'reject_count' => 0, 'batch_code' => "E2E-{$tag}-LOT",
    ]);
    $inspection = app(InspectionService::class)->createForOutput($output, $closed, $maker);
    $inspection->forceFill([
        'status' => 'passed', 'accepted_quantity' => 100, 'reviewed_by' => $production->id,
        'reviewed_at' => now(), 'completed_at' => now(), 'proposed_result' => 'passed',
    ])->save();
    $warehouse = Warehouse::factory()->create([
        'code' => "E2E-WH-{$tag}",
        'name' => "QC E2E warehouse {$tag}",
    ]);
    $zone = WarehouseZone::factory()->create([
        'warehouse_id' => $warehouse->id,
        'name' => "QC finished goods {$tag}",
        'code' => "QZ-{$tag}",
        'zone_type' => 'finished_goods',
    ]);
    $location = WarehouseLocation::factory()->create([
        'zone_id' => $zone->id,
        'code' => "QL-{$tag}",
    ]);
    $item = Item::factory()->create(['code' => $product->part_number, 'item_type' => 'finished_good']);
    $receipt = StockMovement::create([
        'movement_type' => 'production_receipt', 'item_id' => $item->id, 'quantity' => '100.000',
        'unit_cost' => '5.00', 'total_cost' => '500.00', 'to_location_id' => $location->id,
        'reference_type' => 'work_order_output', 'reference_id' => $output->id,
        'created_by' => $production->id, 'lot_number' => $output->batch_code,
    ]);
    $output->forceFill(['production_receipt_movement_id' => $receipt->id, 'production_receipt_handoff_status' => 'generated'])->save();
    StockLevel::create(['item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 100, 'reserved_quantity' => 0, 'weighted_avg_cost' => '5.00']);

    $inlineWo = $makeWo('INLINE', 'completed');
    $inlineProcessInspection = $makePassedProcessQc($inlineWo);
    $inlineOutput = $output->replicate()->forceFill(['work_order_id' => $inlineWo->id, 'batch_code' => "E2E-{$tag}-INLINE", 'production_receipt_movement_id' => null]);
    $inlineOutput->save();
    $inlineReceipt = $receipt->replicate()->forceFill(['reference_id' => $inlineOutput->id, 'lot_number' => $inlineOutput->batch_code]);
    $inlineReceipt->save();
    $inlineOutput->forceFill(['production_receipt_movement_id' => $inlineReceipt->id])->save();
    $inlineInspection = app(InspectionService::class)->createForOutput($inlineOutput, $inlineWo, $maker);
    $inlineInspection->forceFill(['status' => 'passed', 'accepted_quantity' => 100, 'reviewed_by' => $production->id, 'reviewed_at' => now(), 'completed_at' => now()])->save();
    StockLevel::where('item_id', $item->id)->where('location_id', $location->id)->update(['quantity' => 200]);

    $draftWo = $makeWo('DRAFT-QC', 'completed');
    $draftProcessInspection = $makePassedProcessQc($draftWo);
    $draftOutput = WorkOrderOutput::create([
        'work_order_id' => $draftWo->id, 'recorded_by' => $production->id, 'recorded_at' => now(),
        'good_count' => 100, 'reject_count' => 0, 'batch_code' => "E2E-{$tag}-DRAFT",
    ]);
    $draft = app(InspectionService::class)->createForOutput($draftOutput, $draftWo, $maker);
    $stages = ['outgoing' => $draft->hash_id];
    foreach (['incoming', 'in_process', 'supplier_return', 'customer_return'] as $stage) {
        $copy = $draft->replicate();
        $copy->forceFill([
            'inspection_number' => "E2E-{$tag}-{$stage}", 'stage' => $stage,
            'entity_type' => null, 'entity_id' => null, 'work_order_output_id' => null,
        ])->save();
        foreach ($draft->measurements as $measurement) {
            $measurement->replicate()->forceFill(['inspection_id' => $copy->id])->save();
        }
        $stages[$stage] = $copy->hash_id;
    }
    $operationWo = $makeWo('OPERATION', 'in_progress');
    $operationProcessInspection = $makePassedProcessQc($operationWo);
    WorkOrderOutput::create([
        'work_order_id' => $operationWo->id, 'recorded_by' => $production->id, 'recorded_at' => now(),
        'good_count' => 100, 'reject_count' => 0, 'batch_code' => "E2E-{$tag}-OP",
    ]);
    $routing = ProductRouting::create(['product_id' => $product->id, 'version' => 1, 'is_active' => true]);
    $routingOperation = RoutingOperation::create([
        'routing_id' => $routing->id, 'sequence' => 10, 'operation_name' => 'Visual QC gate',
        'work_center' => 'QC', 'cycle_time_minutes' => '1.00', 'qc_required' => true,
    ]);
    $operation = WoOperation::create([
        'work_order_id' => $operationWo->id, 'routing_operation_id' => $routingOperation->id,
        'sequence' => 10, 'operation_name' => 'Visual QC gate', 'status' => 'in_progress', 'operator_id' => $worker->id,
        'qty_planned' => 100, 'qty_completed' => 100,
    ]);
    $machine = Machine::factory()->create(['machine_code' => "E2E-{$tag}", 'status' => 'idle']);
    $mold = Mold::create([
        'mold_code' => "E2E-{$tag}", 'name' => "QC test mold {$tag}", 'product_id' => $product->id,
        'cavity_count' => 1, 'cycle_time_seconds' => 30, 'output_rate_per_hour' => 120,
        'setup_time_minutes' => 0, 'current_shot_count' => 0, 'max_shots_before_maintenance' => 100000,
        'lifetime_max_shots' => 1000000, 'status' => 'available',
    ]);
    $mold->compatibleMachines()->attach($machine->id);

    return [
        'tag' => $tag, 'checker_email' => $checker->email, 'worker_email' => $workerAccount->email, 'maker' => $maker->hash_id,
        'checker' => $checker->hash_id, 'product' => $product->hash_id,
        'closed_wo' => $closed->hash_id, 'closed_wo_number' => $closed->wo_number,
        'closed_process_inspection' => $closedProcessInspection,
        'closed_inspection' => $inspection->hash_id, 'lot' => $output->batch_code,
        'item' => $item->hash_id, 'location' => $location->hash_id, 'stages' => $stages,
        'inline_wo' => $inlineWo->hash_id, 'inline_process_inspection' => $inlineProcessInspection,
        'inline_inspection' => $inlineInspection->hash_id,
        'draft_wo' => $draftWo->hash_id, 'draft_process_inspection' => $draftProcessInspection,
        'operation' => $operation->hash_id, 'operation_wo' => $operationWo->hash_id,
        'operation_process_inspection' => $operationProcessInspection,
        'machine' => $machine->hash_id, 'mold' => $mold->hash_id,
    ];
});
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
file_put_contents(storage_path("app/work-order-qc-e2e-{$tag}.json"), $json);
echo json_encode($manifest, JSON_THROW_ON_ERROR), PHP_EOL;
