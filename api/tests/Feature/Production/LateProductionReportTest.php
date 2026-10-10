<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialHandover;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Models\LateProductionReport;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderCrew;
use App\Modules\Production\Models\WorkOrderMaterial;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\InspectionService;
use App\Modules\Quality\Services\InspectionSpecService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LateProductionReportTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private User $manager;

    private WorkOrder $workOrder;

    private WorkOrderCrew $crew;

    private Carbon $productionStart;

    private Carbon $productionEnd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-08 08:00:00', 'Asia/Manila'));

        $department = Department::firstOrCreate(['code' => 'PROD'], ['name' => 'Production', 'is_active' => true]);
        $employee = Employee::factory()->create(['department_id' => $department->id, 'status' => 'active']);
        $this->worker = User::factory()->withRole('employee')->create([
            'employee_id' => $employee->id,
            'is_active' => true,
        ]);

        $role = Role::firstOrCreate(['slug' => 'production_manager'], ['name' => 'Production manager', 'is_system' => true]);
        foreach (['production.view', 'production.work_orders.view', 'production.wo.record', 'production.work_orders.lifecycle'] as $slug) {
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'module' => 'production']);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $this->manager = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        $this->productionStart = Carbon::parse('2026-10-07 08:00:00', 'Asia/Manila');
        $this->productionEnd = $this->productionStart->copy()->addHours(2);
        $this->workOrder = WorkOrder::factory()->create([
            'status' => 'completed',
            'quantity_target' => 100,
            'actual_start' => $this->productionStart->copy()->subMinutes(30),
            'actual_end' => $this->productionEnd->copy()->addMinutes(30),
        ]);
        $machine = Machine::factory()->create();
        $mold = Mold::create([
            'mold_code' => 'LATE-'.uniqid(),
            'name' => 'Historical production mold',
            'product_id' => $this->workOrder->product_id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 100,
            'setup_time_minutes' => 10,
            'current_shot_count' => 0,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 1000000,
            'status' => 'available',
        ]);
        $mold->compatibleMachines()->sync([$machine->id]);
        $this->workOrder->update(['machine_id' => $machine->id, 'mold_id' => $mold->id]);
        $this->crew = WorkOrderCrew::create([
            'work_order_id' => $this->workOrder->id,
            'recorder_employee_id' => $employee->id,
            'assigned_by' => $this->manager->id,
            'reason' => 'Historical production crew',
            'assigned_at' => $this->productionStart->copy()->subHour(),
            'ended_at' => $this->productionEnd->copy()->addHour(),
        ]);
        $this->crew->members()->create([
            'employee_id' => $employee->id,
            'employee_snapshot' => ['employee_no' => $employee->employee_no, 'name' => $employee->full_name],
        ]);

        $shift = Shift::create(['name' => 'Historical day shift', 'start_time' => '07:00', 'end_time' => '17:00', 'is_active' => true]);
        EmployeeShiftAssignment::create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_date' => $this->productionStart->toDateString(),
            'working_days' => [1, 2, 3, 4, 5, 6, 7],
        ]);
        Attendance::create([
            'employee_id' => $employee->id,
            'date' => $this->productionStart->toDateString(),
            'shift_id' => $shift->id,
            'time_in' => $this->productionStart->copy()->subMinutes(15),
            'time_out' => $this->productionEnd->copy()->addMinutes(15),
            'status' => 'present',
        ]);
        ProductionSchedule::create([
            'work_order_id' => $this->workOrder->id,
            'machine_id' => $machine->id,
            'mold_id' => $mold->id,
            'scheduled_start' => $this->productionStart->copy()->subMinutes(30),
            'scheduled_end' => $this->productionEnd->copy()->addMinutes(30),
            'priority_order' => 1,
            'status' => 'executed',
            'is_confirmed' => true,
            'reserves_capacity' => false,
        ]);
    }

    private function payload(): array
    {
        return [
            'crew_id' => $this->crew->hash_id,
            'source_reference' => 'PRESS-01-BATCH-20261007-01',
            'production_started_at' => $this->productionStart->toIso8601String(),
            'production_finished_at' => $this->productionEnd->toIso8601String(),
            'good_count' => 10,
            'reject_count' => 0,
            'reason' => 'Shift tablet was unavailable during the run.',
        ];
    }

    private function proposalUrl(): string
    {
        return '/api/v1/production/my-work-orders/'.$this->workOrder->hash_id.'/late-production-reports';
    }

    private function finishFirstPayload(): array
    {
        $payload = $this->payload();
        unset($payload['good_count'], $payload['reject_count']);
        $payload['produced_count'] = 10;

        return $payload;
    }

    public function test_finish_first_late_output_gets_final_qc_after_completed_approval_and_preserves_terminal_batch(): void
    {
        config(['inspection.finish_first_wo_enabled' => true]);
        app(InspectionSpecService::class)->upsertForProduct($this->workOrder->product_id, [[
            'parameter_name' => 'Surface condition', 'parameter_type' => 'visual',
            'acceptance_criterion' => 'No cracks, damage or contamination.', 'is_critical' => false,
        ]], $this->manager->id);

        $oldOutput = WorkOrderOutput::create([
            'work_order_id' => $this->workOrder->id, 'recorded_by' => $this->worker->id,
            'recorded_at' => $this->productionStart->copy()->subMinute(), 'good_count' => 5,
            'reject_count' => 0, 'batch_code' => 'FINISH-FIRST-OLD-BATCH',
        ]);
        $this->workOrder->update(['quantity_produced' => 5, 'quantity_good' => 5]);
        $oldInspection = app(InspectionService::class)->createForOutput($oldOutput, $this->workOrder, $this->manager);
        $oldInspection->forceFill(['status' => 'passed', 'inspected_quantity' => 5, 'accepted_quantity' => 5, 'completed_at' => now()])->save();
        \Illuminate\Support\Facades\DB::table('work_orders')->where('id', $this->workOrder->id)->update(['qc_workflow' => 'finish_first_v1']);

        $this->actingAs($this->worker, 'sanctum')
            ->postJson($this->proposalUrl(), $this->finishFirstPayload(), ['X-Idempotency-Key' => 'finish-first-late-completed'])
            ->assertCreated()->assertJsonPath('data.produced_count', 10);
        $report = LateProductionReport::query()->firstOrFail();
        $this->assertSame(10, $report->good_count);
        $this->assertSame(0, $report->reject_count);
        $this->assertSame([], $report->defects);
        $this->assertDatabaseCount('inspections', 1);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/production/late-production-reports/'.$report->hash_id.'/approve', [])
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $output = $report->fresh()->output;
        $this->assertSame(10, $output->good_count);
        $this->assertSame(0, $output->reject_count);
        $this->assertSame(0, $output->held_quantity);
        $this->assertSame('passed', $oldInspection->fresh()->status->value);
        $this->assertSame(2, \Illuminate\Support\Facades\DB::table('inspections')->count());
        $this->assertSame(1, $output->inspections()->where('stage', 'outgoing')->count());
        $this->assertSame('passed', $oldOutput->inspections()->where('stage', 'outgoing')->firstOrFail()->status->value);
        $this->assertSame(0, $this->workOrder->inspections()->where('stage', 'in_process')->count());
    }

    public function test_finish_first_closed_correction_gets_final_qc_only_after_approved_source_is_saved(): void
    {
        config(['inspection.finish_first_wo_enabled' => true]);
        \Illuminate\Support\Facades\DB::table('work_orders')->where('id', $this->workOrder->id)->update(['qc_workflow' => 'finish_first_v1', 'status' => 'closed']);
        app(InspectionSpecService::class)->upsertForProduct($this->workOrder->product_id, [[
            'parameter_name' => 'Surface condition', 'parameter_type' => 'visual',
            'acceptance_criterion' => 'No cracks, damage or contamination.', 'is_critical' => false,
        ]], $this->manager->id);

        $this->actingAs($this->worker, 'sanctum')
            ->postJson($this->proposalUrl(), $this->finishFirstPayload(), ['X-Idempotency-Key' => 'finish-first-late-closed'])
            ->assertCreated()->assertJsonPath('data.status', 'correction');
        $report = LateProductionReport::query()->firstOrFail();
        $this->assertDatabaseCount('inspections', 0);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/production/late-production-reports/'.$report->hash_id.'/approve', ['review_reason' => 'Accounting correction reconciled to the signed shift log.'])
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertSame('approved', $report->fresh()->status->value);
        $this->assertSame(1, $report->fresh()->output->inspections()->where('stage', 'outgoing')->count());
        $this->assertSame('closed', $this->workOrder->fresh()->status->value);
    }

    public function test_recorder_receives_locked_schedule_times_bounded_to_actual_run(): void
    {
        $this->workOrder->forceFill(['batch_number' => 'BATCH-LATE-TEST'])->save();
        $this->actingAs($this->worker, 'sanctum');
        $this->getJson('/api/v1/production/my-work-orders/'.$this->workOrder->hash_id.'?crew_id='.$this->crew->hash_id)
            ->assertOk()
            ->assertJsonPath('data.batch_number', 'BATCH-LATE-TEST')
            ->assertJsonPath('data.report_interval.started_at', $this->workOrder->actual_start->toIso8601String())
            ->assertJsonPath('data.report_interval.finished_at', $this->workOrder->actual_end->toIso8601String())
            ->assertJsonPath('data.report_interval.blocker', null);
        $this->workOrder->forceFill(['actual_start' => $this->productionStart->copy()->addMinutes(15)])->save();
        $this->getJson('/api/v1/production/my-work-orders/'.$this->workOrder->hash_id.'?crew_id='.$this->crew->hash_id)
            ->assertOk()->assertJsonPath('data.report_interval.started_at', $this->workOrder->fresh()->actual_start->toIso8601String());
        $this->workOrder->forceFill(['status' => 'in_progress'])->save();
        $this->getJson('/api/v1/production/my-work-orders/'.$this->workOrder->hash_id)
            ->assertOk()->assertJsonPath('data.batch_number', 'BATCH-LATE-TEST');
    }

    public function test_active_former_recorder_can_propose_completed_work_after_schedule_and_attendance_ended(): void
    {
        $this->actingAs($this->worker, 'sanctum')->postJson($this->proposalUrl(), $this->payload(), ['X-Idempotency-Key' => 'late-shift-1'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.source_reference', 'PRESS-01-BATCH-20261007-01')
            ->assertJsonPath('data.good_count', 10)
            ->assertJsonPath('data.evidence_summary.attendance.state', 'verified');

        $this->assertDatabaseCount('late_production_reports', 1);
        $this->assertDatabaseCount('work_order_outputs', 0);
        $this->assertSame('completed', $this->workOrder->fresh()->status->value);
        $this->assertSame('pending', LateProductionReport::query()->where('work_order_id', $this->workOrder->id)->firstOrFail()->status->value);
    }

    public function test_exact_retry_reuses_proposal_but_changed_payload_or_batch_reference_is_rejected(): void
    {
        $this->actingAs($this->worker, 'sanctum')->postJson($this->proposalUrl(), $this->payload(), ['X-Idempotency-Key' => 'late-shift-2'])
            ->assertCreated();
        $this->postJson($this->proposalUrl(), $this->payload(), ['X-Idempotency-Key' => 'late-shift-2'])->assertOk();

        $this->postJson($this->proposalUrl(), array_replace($this->payload(), ['good_count' => 11]), ['X-Idempotency-Key' => 'late-shift-2'])
            ->assertUnprocessable();
        $this->postJson($this->proposalUrl(), $this->payload(), ['X-Idempotency-Key' => 'late-shift-3'])->assertUnprocessable();
        $this->assertDatabaseCount('late_production_reports', 1);
        $this->assertDatabaseCount('work_order_outputs', 0);
    }

    public function test_invalid_intervals_wrong_workers_and_inactive_workers_cannot_submit(): void
    {
        $this->actingAs($this->worker, 'sanctum')->postJson($this->proposalUrl(), array_replace($this->payload(), [
            'production_started_at' => now()->addMinute()->toIso8601String(),
        ]), ['X-Idempotency-Key' => 'late-future'])->assertUnprocessable();
        $this->postJson($this->proposalUrl(), array_replace($this->payload(), [
            'production_started_at' => $this->productionEnd->toIso8601String(),
        ]), ['X-Idempotency-Key' => 'late-reversed'])->assertUnprocessable();
        $this->postJson($this->proposalUrl(), array_replace($this->payload(), [
            'production_started_at' => $this->workOrder->actual_start->copy()->subSecond()->toIso8601String(),
        ]), ['X-Idempotency-Key' => 'late-before-start'])->assertUnprocessable();

        $wrongEmployee = Employee::factory()->create(['department_id' => $this->worker->employee->department_id, 'status' => 'active']);
        $wrongWorker = User::factory()->withRole('employee')->create(['employee_id' => $wrongEmployee->id, 'is_active' => true]);
        $this->actingAs($wrongWorker, 'sanctum')->postJson($this->proposalUrl(), $this->payload(), ['X-Idempotency-Key' => 'late-wrong-worker'])
            ->assertNotFound();

        $this->worker->update(['is_active' => false]);
        $this->actingAs($this->worker, 'sanctum')->postJson($this->proposalUrl(), $this->payload(), ['X-Idempotency-Key' => 'late-inactive'])
            ->assertUnauthorized();
        $this->assertDatabaseCount('late_production_reports', 0);
    }

    public function test_rejecting_batch_count_must_match_defect_rows(): void
    {
        $type = DefectType::create(['code' => 'LATE1', 'name' => 'Late report defect', 'is_active' => true]);
        $payload = array_replace($this->payload(), ['good_count' => 8, 'reject_count' => 2, 'defects' => [[
            'defect_type_id' => $type->hash_id,
            'count' => 1,
        ]]]);
        $this->actingAs($this->worker, 'sanctum')->postJson($this->proposalUrl(), $payload, ['X-Idempotency-Key' => 'late-defect-sum'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('late_production_reports', 0);
        $this->assertDatabaseCount('work_order_outputs', 0);
    }

    public function test_approval_before_historical_process_qc_holds_entire_batch_and_creates_recovery_evidence(): void
    {
        app(InspectionSpecService::class)->upsertForProduct($this->workOrder->product_id, [[
            'parameter_name' => 'Surface condition',
            'parameter_type' => 'visual',
            'acceptance_criterion' => 'No cracks, damage or contamination.',
            'is_critical' => false,
        ]], $this->manager->id);
        $defect = DefectType::create([
            'code' => 'LTH'.strtoupper(substr(uniqid(), -5)),
            'name' => 'Historical batch defect',
            'is_active' => true,
        ]);
        $payload = array_replace($this->payload(), [
            'good_count' => 8,
            'reject_count' => 2,
            'defects' => [['defect_type_id' => $defect->hash_id, 'count' => 2]],
        ]);
        $this->actingAs($this->worker, 'sanctum')
            ->postJson($this->proposalUrl(), $payload, ['X-Idempotency-Key' => 'late-held-batch'])
            ->assertCreated()
            ->assertJsonPath('data.defects.0.count', 2);
        $report = LateProductionReport::query()->firstOrFail();

        $this->postJson('/api/v1/production/late-production-reports/'.$report->hash_id.'/approve', [])
            ->assertForbidden();
        $this->assertDatabaseCount('work_order_outputs', 0);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/production/late-production-reports/'.$report->hash_id.'/approve', [
                'review_reason' => 'Manager reconciled the historical equipment assignment against the original production records.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.work_order_output_id', fn ($id) => is_string($id) && $id !== '')
            ->assertJsonPath('data.reviewer_name', $this->manager->name)
            ->assertJsonPath('data.output_summary.held_quantity', 10)
            ->assertJsonPath('data.output_summary.physical_count', 10);

        $this->getJson('/api/v1/production/work-orders/'.$this->workOrder->hash_id)->assertOk();

        $output = $report->fresh()->output;
        $this->assertNotNull($output);
        $this->assertSame(10, $output->held_quantity);
        $this->assertSame(0, $output->good_count);
        $this->assertSame(0, $output->reject_count);
        $this->assertSame(10, $output->physical_count);
        $this->assertSame($this->worker->id, $output->recorded_by);
        $this->assertSame($report->production_started_at->copy()->timezone(config('app.timezone'))->toIso8601String(), $output->production_started_at->toIso8601String());
        $this->assertSame($report->production_finished_at->copy()->timezone(config('app.timezone'))->toIso8601String(), $output->production_finished_at->toIso8601String());
        $this->assertSame('verified', $report->fresh()->evidence_summary['resources']['state']);
        $this->assertSame(10, (int) $this->workOrder->fresh()->quantity_produced);
        $this->assertSame(0, (int) $this->workOrder->fresh()->quantity_good);
        $this->assertSame(0, (int) $this->workOrder->fresh()->quantity_rejected);
        $this->assertDatabaseHas('inspections', [
            'work_order_output_id' => $output->id,
            'stage' => 'in_process',
            'batch_quantity' => 10,
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('non_conformance_reports', [
            'affected_quantity' => 10,
            'source' => 'inspection_fail',
        ]);
    }

    public function test_rejected_pieces_can_make_physical_count_exceed_good_target(): void
    {
        app(InspectionSpecService::class)->upsertForProduct($this->workOrder->product_id, [[
            'parameter_name' => 'Surface condition',
            'parameter_type' => 'visual',
            'acceptance_criterion' => 'No cracks, damage or contamination.',
            'is_critical' => false,
        ]], $this->manager->id);
        $defect = DefectType::create([
            'code' => 'LTO'.strtoupper(substr(uniqid(), -5)),
            'name' => 'Historical target excess reject',
            'is_active' => true,
        ]);
        $payload = array_replace($this->payload(), [
            'good_count' => 100,
            'reject_count' => 1,
            'defects' => [['defect_type_id' => $defect->hash_id, 'count' => 1]],
        ]);
        $this->actingAs($this->worker, 'sanctum')
            ->postJson($this->proposalUrl(), $payload, ['X-Idempotency-Key' => 'late-good-target-with-rejects'])
            ->assertCreated()
            ->assertJsonPath('data.good_count', 100)
            ->assertJsonPath('data.reject_count', 1);
        $report = LateProductionReport::query()->firstOrFail();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/production/late-production-reports/'.$report->hash_id.'/approve', [
                'review_reason' => 'Manager reconciled the original equipment assignment against production records.',
            ])
            ->assertOk();

        $output = $report->fresh()->output;
        $this->assertSame(101, $output->held_quantity);
        $this->assertSame(101, (int) $this->workOrder->fresh()->quantity_produced);
        $this->assertSame(0, (int) $this->workOrder->fresh()->quantity_good);
        $this->assertSame(0, (int) $this->workOrder->fresh()->quantity_rejected);
    }

    public function test_material_handover_saved_after_report_interval_blocks_historical_posting(): void
    {
        app(InspectionSpecService::class)->upsertForProduct($this->workOrder->product_id, [[
            'parameter_name' => 'Surface condition',
            'parameter_type' => 'visual',
            'acceptance_criterion' => 'No cracks, damage or contamination.',
            'is_critical' => false,
        ]], $this->manager->id);
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();
        WorkOrderMaterial::create([
            'work_order_id' => $this->workOrder->id,
            'item_id' => $item->id,
            'bom_quantity' => 10,
            'standard_cost' => 1,
            'actual_quantity_issued' => 10,
            'actual_cost' => 10,
        ]);
        $issue = StockMovement::create([
            'item_id' => $item->id,
            'from_location_id' => $location->id,
            'movement_type' => 'material_issue',
            'quantity' => 10,
            'unit_cost' => 1,
            'total_cost' => 10,
            'reference_type' => 'work_order',
            'reference_id' => $this->workOrder->id,
            'created_at' => $this->productionEnd->copy()->addHour(),
        ]);
        MaterialHandover::create([
            'stock_movement_id' => $issue->id,
            'received_by_employee_id' => $this->worker->employee_id,
            'recipient_name' => $this->worker->name,
            'recorded_by' => $this->manager->id,
            'quantity' => 10,
            'handed_over_at' => $this->productionEnd->copy()->addHour(),
        ]);
        $this->actingAs($this->worker, 'sanctum')
            ->postJson($this->proposalUrl(), $this->payload(), ['X-Idempotency-Key' => 'late-material-handover-date'])
            ->assertCreated();
        $report = LateProductionReport::query()->firstOrFail();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/production/late-production-reports/'.$report->hash_id.'/approve', [
                'review_reason' => 'Manager reconciled the material issue against the original handover record.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Warehouse handover is dated after this production. Reconcile the original handover evidence before approval.');

        $this->assertDatabaseCount('work_order_outputs', 0);
        $this->assertSame(0, (int) $this->workOrder->fresh()->quantity_produced);
    }

    public function test_exact_unrecorded_startup_sample_links_existing_trial_without_creating_a_held_batch(): void
    {
        app(InspectionSpecService::class)->upsertForProduct($this->workOrder->product_id, [[
            'parameter_name' => 'Surface condition',
            'parameter_type' => 'visual',
            'acceptance_criterion' => 'No cracks, damage or contamination.',
            'is_critical' => false,
        ]], $this->manager->id);
        $inspection = Inspection::create([
            'inspection_number' => 'LATE-TRIAL-'.strtoupper(substr(uniqid(), -6)),
            'stage' => 'in_process', 'status' => 'draft', 'entity_type' => 'work_order',
            'entity_id' => $this->workOrder->id, 'product_id' => $this->workOrder->product_id,
            'inspection_mode' => 'lot_checklist', 'capture_method' => 'measurement',
            'batch_quantity' => 100, 'sample_size' => 5, 'startup_trial_required' => true,
        ]);
        $payload = array_replace($this->payload(), ['good_count' => 5, 'reject_count' => 0]);
        $this->actingAs($this->worker, 'sanctum')
            ->postJson($this->proposalUrl(), $payload, ['X-Idempotency-Key' => 'late-exact-startup-trial'])
            ->assertCreated();
        $report = LateProductionReport::query()->firstOrFail();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/production/late-production-reports/'.$report->hash_id.'/approve', [])
            ->assertOk();

        $output = $report->fresh()->output;
        $this->assertSame(5, $output->trial_quantity);
        $this->assertSame($inspection->id, $output->trial_inspection_id);
        $this->assertSame(5, $output->physical_count);
        $this->assertSame(0, $output->held_quantity);
        $this->assertSame(0, $output->good_count);
        $this->assertSame(0, $output->reject_count);
        $this->assertSame(5, (int) $this->workOrder->fresh()->quantity_produced);
        $this->assertSame(0, (int) $this->workOrder->fresh()->quantity_good);
        $this->assertSame(1, Inspection::query()->whereKey($inspection->id)->count());
    }

    public function test_qc_released_approval_posts_classified_output_with_saved_defect_rows(): void
    {
        $spec = app(InspectionSpecService::class)->upsertForProduct($this->workOrder->product_id, [[
            'parameter_name' => 'Surface condition',
            'parameter_type' => 'visual',
            'acceptance_criterion' => 'No cracks, damage or contamination.',
            'is_critical' => false,
        ]], $this->manager->id);
        $qcActor = User::factory()->create(['is_active' => true]);
        $inspection = Inspection::create([
            'inspection_number' => 'LATE-QC-'.strtoupper(substr(uniqid(), -6)),
            'stage' => 'in_process',
            'status' => 'passed',
            'entity_type' => 'work_order',
            'entity_id' => $this->workOrder->id,
            'product_id' => $this->workOrder->product_id,
            'inspection_spec_id' => $spec->id,
            'batch_quantity' => 100,
            'sample_size' => 5,
            'inspector_id' => $this->worker->id,
            'completed_at' => $this->productionStart->copy()->subHour(),
        ]);
        $inspection->resultAuthors()->create(['user_id' => $qcActor->id]);
        $defect = DefectType::create([
            'code' => 'LTR'.strtoupper(substr(uniqid(), -5)),
            'name' => 'Historical rejected piece',
            'is_active' => true,
        ]);
        $payload = array_replace($this->payload(), [
            'good_count' => 8,
            'reject_count' => 2,
            'defects' => [['defect_type_id' => $defect->hash_id, 'count' => 2]],
        ]);
        $this->actingAs($this->worker, 'sanctum')
            ->postJson($this->proposalUrl(), $payload, ['X-Idempotency-Key' => 'late-qc-released'])
            ->assertCreated();
        $report = LateProductionReport::query()->firstOrFail();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/production/late-production-reports/'.$report->hash_id.'/approve', [
                'review_reason' => 'Manager reconciled the historical equipment assignment against the original production records.',
            ])
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $this->getJson('/api/v1/production/work-orders/'.$this->workOrder->hash_id)->assertOk();

        $output = $report->fresh()->output;
        $this->assertSame(8, $output->good_count);
        $this->assertSame(2, $output->reject_count);
        $this->assertSame(0, $output->held_quantity);
        $this->assertSame($defect->id, $output->defects()->firstOrFail()->defect_type_id);
        $this->assertSame($report->production_started_at->copy()->timezone(config('app.timezone'))->toIso8601String(), $output->production_started_at->toIso8601String());
        $this->assertSame($report->production_finished_at->copy()->timezone(config('app.timezone'))->toIso8601String(), $output->production_finished_at->toIso8601String());
        $this->assertSame(10, (int) $this->workOrder->fresh()->quantity_produced);
        $this->assertSame(8, (int) $this->workOrder->fresh()->quantity_good);
        $this->assertSame(2, (int) $this->workOrder->fresh()->quantity_rejected);
    }

    public function test_worker_history_and_report_reads_are_limited_to_their_own_crew_and_submissions(): void
    {
        $this->actingAs($this->worker, 'sanctum')
            ->postJson($this->proposalUrl(), $this->payload(), ['X-Idempotency-Key' => 'late-own-history'])
            ->assertCreated();

        $this->getJson('/api/v1/production/my-work-orders/history')
            ->assertOk()
            ->assertJsonPath('data.0.work_order.id', $this->workOrder->hash_id)
            ->assertJsonPath('data.0.can_report_late_production', true)
            ->assertJsonCount(1, 'data.0.late_production_reports');
        $this->getJson('/api/v1/production/my-work-orders/'.$this->workOrder->hash_id)
            ->assertOk()->assertJsonPath('data.historical_report_only', true)
            ->assertJsonCount(1, 'data.late_production_reports');
        $this->getJson('/api/v1/production/my-work-orders/'.$this->workOrder->hash_id.'/late-production-reports')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/production/my-work-orders/'.$this->workOrder->hash_id.'/late-production-report-options?crew_id='.$this->crew->hash_id)
            ->assertOk()->assertJsonStructure(['data' => ['defect_types']]);

        $outsiderEmployee = Employee::factory()->create(['department_id' => $this->worker->employee->department_id, 'status' => 'active']);
        $outsider = User::factory()->withRole('employee')->create(['employee_id' => $outsiderEmployee->id, 'is_active' => true]);
        $this->actingAs($outsider, 'sanctum')->getJson('/api/v1/production/my-work-orders/history')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/production/my-work-orders/'.$this->workOrder->hash_id)->assertNotFound();
        $this->getJson('/api/v1/production/my-work-orders/'.$this->workOrder->hash_id.'/late-production-reports')->assertNotFound();
    }

    public function test_worker_history_embeds_recent_reports_with_full_report_count(): void
    {
        for ($index = 1; $index <= 25; $index++) {
            $startedAt = $this->productionStart->copy()->addMinutes($index);
            LateProductionReport::create([
                'work_order_id' => $this->workOrder->id,
                'work_order_crew_id' => $this->crew->id,
                'recorded_by' => $this->worker->id,
                'source_reference' => 'HISTORY-BATCH-'.$index,
                'production_started_at' => $startedAt,
                'production_finished_at' => $startedAt->copy()->addMinute(),
                'good_count' => 1,
                'reject_count' => 0,
                'defects' => [],
                'reason' => 'Historical fixture row.',
                'crew_snapshot' => [],
                'evidence_summary' => [],
                'status' => 'pending',
                'idempotency_key' => 'history-'.$index,
                'idempotency_fingerprint' => hash('sha256', 'history-'.$index),
            ]);
        }

        $this->actingAs($this->worker, 'sanctum')
            ->getJson('/api/v1/production/my-work-orders/history?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.late_production_report_count', 25)
            ->assertJsonCount(20, 'data.0.late_production_reports')
            ->assertJsonPath('data.0.late_production_reports.0.source_reference', 'HISTORY-BATCH-25')
            ->assertJsonPath('data.0.late_production_reports.19.source_reference', 'HISTORY-BATCH-6');

        $this->getJson('/api/v1/production/my-work-orders/'.$this->workOrder->hash_id)
            ->assertOk()
            ->assertJsonPath('data.late_production_report_count', 25)
            ->assertJsonCount(20, 'data.late_production_reports');
    }

    public function test_worker_output_receipts_return_newest_twenty_with_stable_tie_order(): void
    {
        $this->workOrder->update(['status' => 'in_progress']);
        $this->crew->update(['ended_at' => null]);
        for ($index = 1; $index <= 25; $index++) {
            $output = WorkOrderOutput::make([
                'work_order_id' => $this->workOrder->id,
                'recorded_by' => $this->worker->id,
                'recorded_at' => $this->productionStart->copy()->addMinutes(min($index, 24)),
                'good_count' => 1,
                'reject_count' => 0,
                'batch_code' => 'OUTPUT-BATCH-'.$index,
                'work_order_crew_id' => $this->crew->id,
                'crew_snapshot' => [],
            ]);
            $output->setAttribute('id', 100 - $index);
            $output->save();
        }

        $this->actingAs($this->worker, 'sanctum')
            ->getJson('/api/v1/production/my-work-orders/'.$this->workOrder->hash_id.'/outputs')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('data.0.batch_code', 'OUTPUT-BATCH-24')
            ->assertJsonPath('data.1.batch_code', 'OUTPUT-BATCH-25')
            ->assertJsonPath('data.19.batch_code', 'OUTPUT-BATCH-6');
    }

    public function test_closed_reports_are_correction_only_and_cannot_post_as_production(): void
    {
        $this->workOrder->update(['status' => 'closed']);
        $this->actingAs($this->worker, 'sanctum')
            ->postJson($this->proposalUrl(), $this->payload(), ['X-Idempotency-Key' => 'late-closed-correction'])
            ->assertCreated()->assertJsonPath('data.status', 'correction');
        $report = LateProductionReport::query()->firstOrFail();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/production/late-production-reports/'.$report->hash_id.'/approve', ['review_reason' => 'Accounting correction required.'])
            ->assertUnprocessable();
        $this->assertSame('correction', $report->fresh()->status->value);
        $this->assertDatabaseCount('work_order_outputs', 0);
        $this->assertSame(0, (int) $this->workOrder->fresh()->quantity_produced);
    }

    public function test_rejection_is_audited_without_creating_output_and_cancelled_work_order_is_denied(): void
    {
        $this->actingAs($this->worker, 'sanctum')
            ->postJson($this->proposalUrl(), $this->payload(), ['X-Idempotency-Key' => 'late-reject'])
            ->assertCreated();
        $report = LateProductionReport::query()->firstOrFail();
        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/production/late-production-reports/'.$report->hash_id.'/reject', ['reason' => 'Source batch could not be reconciled.'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.review_reason', 'Source batch could not be reconciled.');
        $this->assertNull($report->fresh()->work_order_output_id);
        $this->assertDatabaseCount('work_order_outputs', 0);

        $this->workOrder->update(['status' => 'cancelled']);
        $this->actingAs($this->worker, 'sanctum')
            ->postJson($this->proposalUrl(), array_replace($this->payload(), ['source_reference' => 'CANCELLED-BATCH']), ['X-Idempotency-Key' => 'late-cancelled'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('late_production_reports', 1);
        $this->assertDatabaseCount('work_order_outputs', 0);
    }

    public function test_open_historical_punch_requires_a_manager_review_reason_without_becoming_a_conflict(): void
    {
        Attendance::query()->firstOrFail()->update(['time_out' => null]);
        app(InspectionSpecService::class)->upsertForProduct($this->workOrder->product_id, [[
            'parameter_name' => 'Surface condition', 'parameter_type' => 'visual',
            'acceptance_criterion' => 'No cracks, damage or contamination.', 'is_critical' => false,
        ]], $this->manager->id);
        $this->actingAs($this->worker, 'sanctum')
            ->postJson($this->proposalUrl(), $this->payload(), ['X-Idempotency-Key' => 'late-attendance-missing'])
            ->assertCreated()->assertJsonPath('data.evidence_summary.attendance.state', 'missing');
        $report = LateProductionReport::query()->firstOrFail();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/production/late-production-reports/'.$report->hash_id.'/approve', [])
            ->assertUnprocessable();
        $this->assertDatabaseCount('work_order_outputs', 0);
        $this->postJson('/api/v1/production/late-production-reports/'.$report->hash_id.'/approve', [
            'review_reason' => 'Shift supervisor confirmed crew presence; attendance record is missing.',
        ])->assertOk()->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.review_reason', 'Shift supervisor confirmed crew presence; attendance record is missing.');
        $this->assertDatabaseCount('work_order_outputs', 1);
    }

    public function test_report_outside_saved_shift_bounds_is_a_conflict_and_cannot_be_approved(): void
    {
        Shift::query()->where('name', 'Historical day shift')->firstOrFail()->update(['start_time' => '09:00', 'end_time' => '17:00']);
        $this->actingAs($this->worker, 'sanctum')
            ->postJson($this->proposalUrl(), $this->payload(), ['X-Idempotency-Key' => 'late-shift-conflict'])
            ->assertCreated()->assertJsonPath('data.evidence_summary.attendance.state', 'conflicting');
        $report = LateProductionReport::query()->firstOrFail();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/production/late-production-reports/'.$report->hash_id.'/approve', ['review_reason' => 'Shift reviewed.'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('work_order_outputs', 0);
        $this->assertSame('pending', $report->fresh()->status->value);
    }

    public function test_overlap_with_another_historical_crew_blocks_approval_using_wall_time_bounds(): void
    {
        $otherWorkOrder = WorkOrder::factory()->create([
            'status' => 'completed', 'actual_start' => $this->productionStart->copy()->subHour(),
            'actual_end' => $this->productionEnd->copy()->addHour(),
        ]);
        $otherCrew = WorkOrderCrew::create([
            'work_order_id' => $otherWorkOrder->id, 'recorder_employee_id' => $this->worker->employee_id,
            'assigned_by' => $this->manager->id, 'reason' => 'Overlapping historical crew fixture',
            'assigned_at' => $this->productionStart->copy()->subHour(), 'ended_at' => $this->productionEnd->copy()->addHour(),
        ]);
        $otherCrew->members()->create([
            'employee_id' => $this->worker->employee_id,
            'employee_snapshot' => ['employee_no' => $this->worker->employee->employee_no, 'name' => $this->worker->employee->full_name],
        ]);
        $this->assertTrue(WorkOrderCrew::query()->where('work_order_id', '!=', $this->workOrder->id)
            ->where('assigned_at', '<', $this->productionEnd->format('Y-m-d H:i:s'))
            ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $this->productionStart->format('Y-m-d H:i:s')))
            ->whereHas('members', fn ($query) => $query->where('employee_id', $this->worker->employee_id))->exists());
        $this->actingAs($this->worker, 'sanctum')
            ->postJson($this->proposalUrl(), $this->payload(), ['X-Idempotency-Key' => 'late-overlapping-crew'])
            ->assertCreated()->assertJsonPath('data.evidence_summary.crew.state', 'conflicting');
        $report = LateProductionReport::query()->firstOrFail();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/production/late-production-reports/'.$report->hash_id.'/approve', ['review_reason' => 'Check conflicting attendance.'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Historical crew evidence conflicts with this report. Reconcile the production history before approval.');
        $this->assertDatabaseCount('work_order_outputs', 0);
    }
}
