<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialHandover;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WoOperation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderMaterial;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Production\Resources\WorkOrderOutputResource;
use App\Modules\Production\Services\WorkOrderMaterialUsageService;
use App\Modules\Production\Services\WorkOrderOutputService;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\HasAssignedProductionCrew;
use Tests\TestCase;

class WorkOrderStartupTrialTest extends TestCase
{
    use HasAssignedProductionCrew, RefreshDatabase;

    private WorkOrder $wo;

    private Inspection $inspection;

    private User $worker;

    private User $manager;

    private StockMovement $issue;

    private array $data;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfDay()->addHours(8));
        $this->seed(RolePermissionSeeder::class);
        $this->worker = User::factory()->create();
        $this->manager = User::factory()->withRole('production_manager')->create();
        $machine = Machine::factory()->create();
        $this->wo = WorkOrder::factory()->create([
            'status' => 'in_progress', 'quantity_target' => 100, 'material_plan_source' => 'bom',
            'actual_start' => now(), 'planned_start' => now(), 'planned_end' => now()->addHours(2),
            'machine_id' => $machine->id,
        ]);
        $mold = Mold::create([
            'mold_code' => 'TRIAL-'.uniqid(), 'name' => 'Trial mold', 'product_id' => $this->wo->product_id,
            'cavity_count' => 1, 'cycle_time_seconds' => 30, 'output_rate_per_hour' => 100,
            'setup_time_minutes' => 10, 'current_shot_count' => 0, 'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 1000000, 'status' => 'in_use', 'current_work_order_id' => $this->wo->id,
        ]);
        $mold->compatibleMachines()->sync([$machine->id]);
        $machine->update(['status' => 'running', 'current_work_order_id' => $this->wo->id]);
        $this->wo->update(['mold_id' => $mold->id]);
        ProductionSchedule::create([
            'work_order_id' => $this->wo->id, 'machine_id' => $machine->id, 'mold_id' => $mold->id,
            'scheduled_start' => now(), 'scheduled_end' => now()->addHours(2), 'priority_order' => 0,
            'status' => 'confirmed', 'is_confirmed' => true, 'reserves_capacity' => true,
        ]);
        $crew = $this->assignProductionCrew($this->wo, $this->worker);
        $this->data = ['trial_quantity' => 8, 'crew_id' => $crew->id];
        $this->inspection = Inspection::create([
            'inspection_number' => 'QC-TRIAL-'.uniqid(), 'stage' => 'in_process', 'status' => 'draft',
            'entity_type' => 'work_order', 'entity_id' => $this->wo->id, 'product_id' => $this->wo->product_id,
            'inspection_mode' => 'lot_checklist', 'capture_method' => 'measurement', 'batch_quantity' => 100,
            'sample_size' => 8, 'accept_count' => 0, 'reject_count' => 1,
        ]);
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();
        WorkOrderMaterial::create([
            'work_order_id' => $this->wo->id, 'item_id' => $item->id,
            'bom_quantity' => 10, 'standard_cost' => 10, 'actual_quantity_issued' => 10, 'actual_cost' => 10,
        ]);
        $this->issue = StockMovement::create([
            'item_id' => $item->id, 'from_location_id' => $location->id, 'movement_type' => 'material_issue',
            'quantity' => 10, 'unit_cost' => 1, 'total_cost' => 10, 'reference_type' => 'work_order',
            'reference_id' => $this->wo->id, 'created_at' => now(),
        ]);
        $this->handover();
    }

    public function test_capture_records_physical_pieces_once_without_good_stock(): void
    {
        $output = $this->capture();
        $this->assertSame(8, $output->trial_quantity);
        $this->assertSame($this->inspection->id, $output->trial_inspection_id);
        $this->assertSame(0, $output->good_count);
        $this->assertSame(0, $output->reject_count);
        $this->assertSame(8, $output->physical_count);
        $this->assertSame(8, (int) $this->wo->fresh()->quantity_produced);
        $this->assertSame(0, (int) $this->wo->fresh()->quantity_good);
        $this->assertSame(8, (int) $this->wo->mold->fresh()->current_shot_count);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('inspections', 1);
        $this->assertTrue(app(WorkOrderMaterialUsageService::class)->hasRecordedProduction($this->wo));
        $resource = (new WorkOrderOutputResource($output))->resolve();
        $this->assertSame(8, $resource['physical_count']);
        $this->assertSame(8, $resource['trial_quantity']);
        $this->assertSame('awaiting_qc', $this->service()->trialState($this->wo)['state']);
    }

    public function test_trial_count_must_equal_whole_pinned_sample(): void
    {
        $this->requireTrial();
        foreach ([0, -1, 1.5, 7, 9, 101] as $count) {
            $this->denied(fn () => $this->service()->recordTrial($this->wo, array_replace($this->data, ['trial_quantity' => $count]), $this->worker->id, 'bad-count'));
        }
        $this->assertDatabaseCount('work_order_outputs', 0);
        $this->assertSame(0, (int) $this->wo->fresh()->quantity_produced);
        $this->assertSame(0, (int) $this->wo->mold->fresh()->current_shot_count);
    }

    public function test_trial_sample_is_capped_by_target(): void
    {
        $this->wo->update(['quantity_target' => 3]);
        $output = $this->capture(['trial_quantity' => 3]);
        $this->assertSame(3, $output->trial_quantity);
    }

    public function test_exact_retry_survives_terminal_changes_and_changed_payload_fails(): void
    {
        $output = $this->capture();
        $this->inspection->update(['status' => 'passed']);
        $this->wo->update(['status' => 'completed']);
        $this->wo->crews()->update(['ended_at' => now()]);
        $this->wo->machine->update(['current_work_order_id' => null, 'status' => 'idle']);
        $replay = $this->service()->recordTrial($this->wo, $this->data, $this->worker->id, 'startup-trial');
        $this->assertSame($output->id, $replay->id);
        foreach ([['trial_quantity' => 7], ['crew_id' => $this->data['crew_id'] + 1], ['recording_reason' => 'Changed'], ['material_handover_reason' => 'Changed']] as $change) {
            $this->denied(fn () => $this->service()->recordTrial($this->wo, array_replace($this->data, $change), $this->worker->id, 'startup-trial'));
        }
        $this->denied(fn () => $this->service()->recordTrial($this->wo, $this->data, $this->manager->id, 'startup-trial'));
        $this->assertSame(1, $this->wo->outputs()->count());
        $this->assertSame(8, (int) $this->wo->fresh()->quantity_produced);
        $this->assertSame(8, (int) $this->wo->mold->fresh()->current_shot_count);
    }

    public function test_second_key_cannot_capture_second_sample_batch(): void
    {
        $this->capture();
        $this->denied(fn () => $this->service()->recordTrial($this->wo, $this->data, $this->worker->id, 'second-trial'));
        $this->assertSame(1, $this->wo->outputs()->count());
    }

    public function test_trial_requires_handover_coverage_owned_equipment_and_present_crew(): void
    {
        $this->requireTrial();
        MaterialHandover::query()->delete();
        $this->denied(fn () => $this->service()->recordTrial($this->wo, $this->data, $this->worker->id, 'guard'));
        $this->handover();
        $this->wo->materials()->update(['actual_quantity_issued' => 0]);
        $this->denied(fn () => $this->service()->recordTrial($this->wo, $this->data, $this->worker->id, 'guard'));
        $this->wo->materials()->update(['actual_quantity_issued' => 10]);
        $this->wo->machine->update(['current_work_order_id' => null]);
        $this->denied(fn () => $this->service()->recordTrial($this->wo, $this->data, $this->worker->id, 'guard'));
        $this->wo->machine->update(['current_work_order_id' => $this->wo->id]);
        $this->worker->employee->attendances()->update(['time_out' => now()]);
        $this->denied(fn () => $this->service()->recordTrial($this->wo, $this->data + ['recording_reason' => 'Earlier output.'], $this->manager->id, 'guard'));
        $this->assertDatabaseCount('work_order_outputs', 0);
    }

    public function test_inactive_and_unrelated_roles_cannot_capture_trial(): void
    {
        $this->requireTrial();
        foreach (['employee', 'qc_inspector', 'warehouse_staff', 'hr_staff', 'sales_staff'] as $role) {
            $actor = User::factory()->withRole($role)->create();
            try {
                $this->service()->recordTrial($this->wo, $this->data, $actor->id, 'bad-role');
                $this->fail('An unrelated actor must not manufacture trial evidence.');
            } catch (BusinessRuleException|HttpException|ModelNotFoundException) {
                $this->assertDatabaseCount('work_order_outputs', 0);
            }
        }
        $this->worker->update(['is_active' => false]);
        $this->expectException(HttpException::class);
        $this->service()->recordTrial($this->wo, $this->data, $this->worker->id, 'inactive');
    }

    public function test_paused_failed_operation_and_repair_contexts_cannot_capture(): void
    {
        $this->requireTrial();
        $this->wo->update(['status' => 'paused']);
        $this->denied(fn () => $this->service()->recordTrial($this->wo, $this->data, $this->worker->id, 'guard'));
        $this->wo->update(['status' => 'in_progress']);
        $this->inspection->update(['status' => 'failed']);
        $this->denied(fn () => $this->service()->recordTrial($this->wo, $this->data, $this->worker->id, 'guard'));
        $this->inspection->update(['status' => 'draft']);
        $this->wo->update(['rework_source_output_id' => WorkOrderOutput::create([
            'work_order_id' => $this->wo->id, 'recorded_by' => $this->worker->id, 'recorded_at' => now(),
            'good_count' => 1, 'reject_count' => 0,
        ])->id]);
        $this->denied(fn () => $this->service()->recordTrial($this->wo, $this->data, $this->worker->id, 'guard'));
        $this->assertDatabaseCount('work_order_outputs', 1);
    }

    public function test_evidence_requires_samples_and_final_coverage_matches_actual_trial(): void
    {
        $this->requireTrial();
        $this->denied(fn () => $this->service()->assertTrialEvidence($this->inspection));
        $this->capture();
        $this->service()->assertTrialEvidence($this->inspection, 8, true);
        $this->denied(fn () => $this->service()->assertTrialEvidence($this->inspection, 7, true));
        $this->denied(fn () => $this->service()->assertTrialEvidence($this->inspection, 100, true));
    }

    public function test_pending_trial_receipt_retry_is_refused_without_inventory(): void
    {
        $output = $this->capture();
        $this->denied(fn () => $this->service()->retryProductionReceipt($output, $this->manager));
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertNull($output->fresh()->production_receipt_movement_id);
    }

    public function test_pass_settles_original_sample_and_receives_good_once(): void
    {
        $output = $this->capture();
        $this->finishedGoods();
        $this->inspection->update(['status' => 'passed']);
        DB::transaction(fn () => $this->service()->settleTrial($this->inspection, $this->manager, 8));
        DB::transaction(fn () => $this->service()->settleTrial($this->inspection, $this->manager, 8));
        $this->assertSame(8, $output->fresh()->good_count);
        $this->assertSame(0, $output->fresh()->reject_count);
        $this->assertNotNull($output->fresh()->trial_settled_at);
        $this->assertSame(8, (int) $this->wo->fresh()->quantity_produced);
        $this->assertSame(8, (int) $this->wo->fresh()->quantity_good);
        $this->assertSame(8, (int) $this->wo->mold->fresh()->current_shot_count);
        $this->assertSame(1, StockMovement::where('movement_type', 'production_receipt')->count());
        $this->assertSame('8.000', $output->fresh()->productionReceiptMovement->quantity);
        $this->assertSame('released', $this->service()->trialState($this->wo)['state']);
    }

    public function test_recovery_classifies_only_sample_and_rejects_changed_disposition(): void
    {
        $output = $this->capture();
        $this->inspection->update(['status' => 'failed']);
        $defect = DefectType::create(['code' => 'TRIALSCRAP', 'name' => 'Trial scrap', 'is_active' => true]);
        $this->denied(fn () => DB::transaction(fn () => $this->service()->settleTrial($this->inspection, $this->manager, 6)));
        $defects = [['defect_type_id' => $defect->id, 'count' => 2]];
        $this->denied(fn () => DB::transaction(fn () => $this->service()->settleTrial($this->inspection, $this->manager, 6, $defects)));
        $ncr = new NonConformanceReport;
        $ncr->forceFill([
            'ncr_number' => 'NCR-TRIAL-'.uniqid(), 'source' => 'inspection_fail', 'severity' => 'medium',
            'status' => 'in_progress', 'inspection_id' => $this->inspection->id, 'product_id' => $this->wo->product_id,
            'affected_quantity' => 8, 'defect_description' => 'Startup sample needs confirmed disposition.',
            'disposition' => 'rework', 'created_by' => $this->worker->id, 'mrb_accepted_quantity' => '6.000',
            'sorting_inspected_quantity' => '8.000', 'sorting_evidence_reference' => 'Independent sample recovery',
            'sorting_performed_by' => $this->worker->id, 'sorting_approved_by' => $this->manager->id, 'sorting_approved_at' => now(),
        ])->save();
        DB::transaction(fn () => $this->service()->settleTrial($this->inspection, $this->manager, 6, $defects));
        $this->assertSame(6, $output->fresh()->good_count);
        $this->assertSame(2, $output->fresh()->reject_count);
        $this->assertSame(8, (int) $this->wo->fresh()->quantity_produced);
        $this->assertSame(6, (int) $this->wo->fresh()->quantity_good);
        $this->assertSame(2, (int) $this->wo->fresh()->quantity_rejected);
        $this->assertSame(8, (int) $this->wo->mold->fresh()->current_shot_count);
        $this->assertSame(2, $output->defects()->sum('count'));
        $this->denied(fn () => DB::transaction(fn () => $this->service()->settleTrial($this->inspection, $this->manager, 8)));
    }

    public function test_pending_trials_add_to_routing_material_coverage_without_double_counting(): void
    {
        $this->capture();
        WoOperation::create([
            'work_order_id' => $this->wo->id, 'sequence' => 1, 'operation_name' => 'Injection',
            'status' => 'in_progress', 'qty_planned' => 100, 'qty_completed' => 100, 'qty_scrapped' => 0,
        ]);
        $this->assertSame('108.0000', app(WorkOrderMaterialUsageService::class)->grossProductionUnits($this->wo->fresh()));
        $this->denied(fn () => app(WorkOrderMaterialUsageService::class)->assertProductionCoverage($this->wo->fresh()));
    }

    public function test_trial_quantity_and_source_cannot_be_edited_after_capture(): void
    {
        $output = $this->capture();
        $this->denied(fn () => $output->update(['trial_quantity' => 9]));
        $this->denied(fn () => $output->fresh()->update(['trial_inspection_id' => null]));
        $this->denied(fn () => $output->fresh()->delete());
        $this->assertSame(8, $output->fresh()->trial_quantity);
        $this->assertSame($this->inspection->id, $output->fresh()->trial_inspection_id);
    }

    public function test_captured_inspection_requirement_and_sample_source_cannot_be_changed(): void
    {
        $this->capture();
        foreach ([['startup_trial_required' => false], ['sample_size' => 9], ['entity_id' => $this->wo->id + 1], ['status' => 'cancelled']] as $change) {
            $this->denied(fn () => $this->inspection->fresh()->update($change));
        }
        $this->assertTrue($this->inspection->fresh()->startup_trial_required);
        $this->assertSame(8, $this->inspection->fresh()->sample_size);
    }

    private function service(): WorkOrderOutputService
    {
        return app(WorkOrderOutputService::class);
    }

    private function requireTrial(): void
    {
        $this->assertTrue(method_exists($this->service(), 'recordTrial'), 'Dedicated physical startup trial capture is missing.');
        $this->inspection->update(['startup_trial_required' => true]);
    }

    private function capture(array $data = []): WorkOrderOutput
    {
        $this->requireTrial();

        return $this->service()->recordTrial($this->wo, array_replace($this->data, $data), $this->worker->id, 'startup-trial');
    }

    private function denied(callable $action): void
    {
        try {
            $action();
            $this->fail('Invalid trial action must fail without changing physical evidence.');
        } catch (BusinessRuleException) {
            $this->assertTrue(true);
        }
    }

    private function handover(): void
    {
        MaterialHandover::create([
            'stock_movement_id' => $this->issue->id, 'received_by_employee_id' => $this->worker->employee_id,
            'recipient_name' => $this->worker->employee->full_name, 'recorded_by' => $this->manager->id,
            'quantity' => $this->issue->quantity, 'handed_over_at' => now(),
        ]);
    }

    private function finishedGoods(): void
    {
        $this->wo->product->update(['unit_of_measure' => 'pcs', 'standard_cost' => 5]);
        Item::factory()->create(['code' => $this->wo->product->part_number, 'item_type' => 'finished_good', 'unit_of_measure' => 'pcs']);
        $zone = WarehouseZone::factory()->create(['zone_type' => 'finished_goods']);
        WarehouseLocation::factory()->create(['zone_id' => $zone->id, 'is_active' => true]);
    }
}
