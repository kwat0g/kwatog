<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialHandover;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\ProductRouting;
use App\Modules\Production\Models\RoutingOperation;
use App\Modules\Production\Models\WoOperation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderMaterial;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Production\Services\WoOperationService;
use App\Modules\Quality\Models\Inspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HasAssignedProductionCrew;
use Tests\Support\HasPassedInProcessQc;
use Tests\TestCase;

class WoOperationStartupTrialTest extends TestCase
{
    use HasAssignedProductionCrew, HasPassedInProcessQc, RefreshDatabase;

    private WorkOrder $wo;

    private WoOperation $operation;

    private Inspection $inspection;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfDay()->addHours(8));
        $this->worker = User::factory()->create();
        $machine = Machine::factory()->create();
        $this->wo = WorkOrder::factory()->create(['status' => 'in_progress', 'actual_start' => now(),
            'planned_start' => now(), 'planned_end' => now()->addHours(2), 'quantity_target' => 100,
            'machine_id' => $machine->id]);
        $mold = Mold::create(['mold_code' => 'OP-'.uniqid(), 'name' => 'Operation trial mold',
            'product_id' => $this->wo->product_id, 'cavity_count' => 1, 'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 100, 'setup_time_minutes' => 10, 'current_shot_count' => 0,
            'max_shots_before_maintenance' => 100000, 'lifetime_max_shots' => 1000000,
            'status' => 'in_use', 'current_work_order_id' => $this->wo->id]);
        $mold->compatibleMachines()->sync([$machine->id]);
        $machine->update(['status' => 'running', 'current_work_order_id' => $this->wo->id]);
        $this->wo->update(['mold_id' => $mold->id]);
        $this->assignProductionCrew($this->wo, $this->worker);
        $this->operation = WoOperation::create(['work_order_id' => $this->wo->id, 'sequence' => 1,
            'operation_name' => 'Molding', 'status' => 'pending', 'qty_planned' => 100,
            'qty_completed' => 0, 'qty_scrapped' => 0, 'operator_id' => $this->worker->employee_id]);
        $this->inspection = Inspection::create(['inspection_number' => 'QC-OP-'.uniqid(), 'stage' => 'in_process',
            'status' => 'draft', 'entity_type' => 'work_order', 'entity_id' => $this->wo->id,
            'product_id' => $this->wo->product_id, 'batch_quantity' => 100, 'sample_size' => 8,
            'inspector_id' => $this->wo->created_by]);
        $this->inspection->forceFill(['startup_trial_required' => true])->save();
    }

    public function test_setup_starts_and_ends_before_trial_qc_without_producing_pieces(): void
    {
        $service = app(WoOperationService::class);
        $service->startSetup($this->operation, $this->worker->employee);
        $this->assertSame('setup', $this->operation->fresh()->status->value);
        $service->endSetup($this->operation->fresh());
        $this->assertNotNull($this->operation->fresh()->setup_end);
        $this->assertSame('0.0000', $this->operation->fresh()->qty_completed);
        $this->assertDatabaseCount('production_logs', 2);
        $this->assertDatabaseCount('work_order_outputs', 0);
        $this->denied(fn () => $service->startOperation($this->operation->fresh(), $this->worker->employee));
        $this->denied(fn () => $service->skipOperation($this->operation->fresh(), 'Skip', $this->worker->employee));
    }

    public function test_setup_requires_current_attendance_equipment_and_material_handover(): void
    {
        $service = app(WoOperationService::class);
        $this->wo->machine->update(['current_work_order_id' => null]);
        $this->denied(fn () => $service->startSetup($this->operation, $this->worker->employee));
        $this->wo->machine->update(['current_work_order_id' => $this->wo->id]);
        $attendance = Attendance::where('employee_id', $this->worker->employee_id)->firstOrFail();
        $attendance->update(['time_out' => now()]);
        $this->denied(fn () => $service->startSetup($this->operation, $this->worker->employee));
        $attendance->update(['time_out' => null]);
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();
        $material = WorkOrderMaterial::create(['work_order_id' => $this->wo->id, 'item_id' => $item->id,
            'bom_quantity' => 10, 'standard_cost' => 1, 'actual_quantity_issued' => 0, 'actual_cost' => 0]);
        $this->denied(fn () => $service->startSetup($this->operation, $this->worker->employee));
        $material->update(['actual_quantity_issued' => 10]);
        $issue = StockMovement::create(['item_id' => $item->id, 'from_location_id' => $location->id,
            'movement_type' => 'material_issue', 'quantity' => 10, 'unit_cost' => 1, 'total_cost' => 10,
            'reference_type' => 'work_order', 'reference_id' => $this->wo->id, 'created_at' => now()]);
        $this->denied(fn () => $service->startSetup($this->operation, $this->worker->employee));
        MaterialHandover::create(['stock_movement_id' => $issue->id, 'recorded_by' => $this->worker->id,
            'received_by_employee_id' => $this->worker->employee_id, 'recipient_name' => 'Assigned crew', 'quantity' => 10, 'handed_over_at' => now()]);
        $service->startSetup($this->operation, $this->worker->employee);
        $this->assertSame('setup', $this->operation->fresh()->status->value);
    }

    public function test_failed_or_nontrial_qc_never_allows_setup(): void
    {
        $this->inspection->update(['status' => 'failed']);
        $this->denied(fn () => app(WoOperationService::class)->startSetup($this->operation, $this->worker->employee));
        $this->inspection->forceFill(['status' => 'draft', 'startup_trial_required' => false])->save();
        $this->denied(fn () => app(WoOperationService::class)->startSetup($this->operation, $this->worker->employee));
        $this->assertDatabaseCount('production_logs', 0);
    }

    public function test_final_reconciliation_excludes_classified_startup_samples(): void
    {
        $this->inspection->forceFill(['startup_trial_required' => false])->save();
        $this->passInProcessQc($this->wo);
        WorkOrderOutput::create(['work_order_id' => $this->wo->id, 'good_count' => 8, 'reject_count' => 0,
            'trial_inspection_id' => $this->inspection->id, 'trial_quantity' => 8, 'trial_settled_at' => now(), 'recorded_by' => $this->worker->id, 'recorded_at' => now()]);
        WorkOrderOutput::create(['work_order_id' => $this->wo->id, 'good_count' => 10, 'reject_count' => 0, 'recorded_by' => $this->worker->id, 'recorded_at' => now()]);
        $this->operation->update(['status' => 'in_progress', 'qty_completed' => 10]);
        app(WoOperationService::class)->completeOperation($this->operation->fresh());
        $this->assertSame('completed', $this->operation->fresh()->status->value);
    }

    public function test_operation_qc_refuses_zero_or_fractional_physical_output(): void
    {
        $this->inspection->forceFill(['startup_trial_required' => false])->save();
        $this->passInProcessQc($this->wo);
        $routing = ProductRouting::create(['product_id' => $this->wo->product_id, 'version' => 1, 'is_active' => true]);
        $step = RoutingOperation::create(['routing_id' => $routing->id, 'sequence' => 1, 'operation_name' => 'Molding', 'cycle_time_minutes' => 1, 'qc_required' => true]);
        $this->operation->update(['status' => 'in_progress', 'routing_operation_id' => $step->id]);
        WoOperation::create(['work_order_id' => $this->wo->id, 'sequence' => 2, 'operation_name' => 'Packing', 'status' => 'pending', 'qty_planned' => 100]);
        foreach ([0, 1.5] as $quantity) {
            $this->operation->update(['qty_completed' => $quantity]);
            try {
                app(WoOperationService::class)->completeOperation($this->operation->fresh());
                $this->fail('No operation QC without actual whole pieces.');
            } catch (BusinessRuleException $error) {
                $this->assertStringContainsString('positive whole recorded quantity', $error->getMessage());
            }
            $this->assertSame(0, Inspection::where('wo_operation_id', $this->operation->id)->count());
        }
    }

    public function test_operation_qc_pending_is_reused_and_only_approved_own_qc_allows_completion(): void
    {
        $this->inspection->forceFill(['startup_trial_required' => false])->save();
        $this->passInProcessQc($this->wo);
        $routing = ProductRouting::create(['product_id' => $this->wo->product_id, 'version' => 1, 'is_active' => true]);
        $step = RoutingOperation::create(['routing_id' => $routing->id, 'sequence' => 1, 'operation_name' => 'Molding', 'cycle_time_minutes' => 1, 'qc_required' => true]);
        $this->operation->update(['status' => 'in_progress', 'routing_operation_id' => $step->id, 'qty_completed' => 6]);
        WoOperation::create(['work_order_id' => $this->wo->id, 'sequence' => 2, 'operation_name' => 'Packing', 'status' => 'pending', 'qty_planned' => 100]);
        $own = Inspection::create(['inspection_number' => 'QC-OWN-'.uniqid(), 'stage' => 'in_process', 'status' => 'draft',
            'entity_type' => 'work_order', 'entity_id' => $this->wo->id, 'product_id' => $this->wo->product_id,
            'wo_operation_id' => $this->operation->id, 'batch_quantity' => 6, 'sample_size' => 6, 'inspector_id' => $this->wo->created_by]);
        $service = app(WoOperationService::class);
        $this->denied(fn () => $service->completeOperation($this->operation->fresh()));
        $this->assertSame(1, Inspection::where('wo_operation_id', $this->operation->id)->count());
        $own->update(['status' => 'passed']);
        $this->denied(fn () => $service->completeOperation($this->operation->fresh()));
        $reviewer = User::factory()->create();
        $own->update(['status' => 'passed', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'completed_at' => now()]);
        $service->completeOperation($this->operation->fresh());
        $this->assertSame('completed', $this->operation->fresh()->status->value);
    }

    private function denied(callable $action): void
    {
        try {
            $action();
            $this->fail('Production gate must reject this action.');
        } catch (BusinessRuleException) {
        }
    }
}
