<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialHandover;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderMaterial;
use App\Modules\Production\Resources\WorkOrderResource;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\InspectionSpecService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HasAssignedProductionCrew;
use Tests\TestCase;

class WorkOrderTrialRoutesTest extends TestCase
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

    public function test_assigned_trial_route_captures_and_replays_actual_sample(): void
    {
        $this->inspection->update(['startup_trial_required' => true]);
        $data = ['trial_quantity' => 8, 'crew_id' => $this->wo->crews()->whereNull('ended_at')->first()->hash_id];
        $path = '/api/v1/production/my-work-orders/'.$this->wo->hash_id.'/trial-outputs';
        $this->actingAs($this->worker)->postJson($path, $data, ['X-Idempotency-Key' => 'trial-http'])
            ->assertSuccessful()->assertJsonPath('data.trial_quantity', 8)->assertJsonPath('data.good_count', 0);
        $this->postJson($path, $data, ['X-Idempotency-Key' => 'trial-http'])->assertSuccessful();
        $this->assertSame(1, $this->wo->outputs()->count());
        $this->assertSame(8, (int) $this->wo->fresh()->quantity_produced);
    }

    public function test_manager_trial_route_requires_reason_and_returns_pending_physical_count(): void
    {
        $this->inspection->update(['startup_trial_required' => true]);
        $data = ['trial_quantity' => 8, 'crew_id' => $this->wo->crews()->whereNull('ended_at')->first()->hash_id];
        $path = '/api/v1/production/work-orders/'.$this->wo->hash_id.'/trial-outputs';
        $this->actingAs($this->manager)->postJson($path, $data, ['X-Idempotency-Key' => 'trial-manager'])->assertStatus(422);
        $this->postJson($path, $data + ['recording_reason' => 'Recorded actual setup samples'], ['X-Idempotency-Key' => 'trial-manager'])
            ->assertSuccessful()->assertJsonPath('data.trial_quantity', 8)->assertJsonPath('data.physical_count', 8);
    }

    public function test_wrong_roles_and_missing_key_cannot_capture(): void
    {
        $this->inspection->update(['startup_trial_required' => true]);
        $path = '/api/v1/production/work-orders/'.$this->wo->hash_id.'/trial-outputs';
        $data = ['trial_quantity' => 8, 'crew_id' => $this->wo->crews()->whereNull('ended_at')->first()->hash_id];
        foreach (['warehouse_staff', 'qc_inspector', 'hr_officer'] as $role) {
            $this->actingAs(User::factory()->withRole($role)->create())->postJson($path, $data, ['X-Idempotency-Key' => 'forbidden'])->assertForbidden();
        }
        $this->actingAs($this->manager)->postJson($path, $data)->assertStatus(422);
        $this->assertDatabaseCount('work_order_outputs', 0);
    }

    public function test_assigned_page_exposes_trial_access_while_ordinary_output_is_blocked(): void
    {
        $this->inspection->update(['startup_trial_required' => true]);
        $this->actingAs($this->worker)->getJson('/api/v1/production/my-work-orders/'.$this->wo->hash_id)
            ->assertOk()->assertJsonPath('data.can_record', false)->assertJsonPath('data.can_record_trial', true)
            ->assertJsonPath('data.startup_trial.state', 'awaiting_samples')->assertJsonPath('data.startup_trial.sample_size', 8);
        $resource = (new WorkOrderResource($this->wo))->resolve();
        $this->assertSame('awaiting_samples', $resource['startup_trial']['state']);
    }

    public function test_qc_recovery_can_read_defects_without_production_permissions(): void
    {
        $checker = User::factory()->withRole('qc_inspector')->create();
        $this->actingAs($checker)->getJson('/api/v1/quality/defect-types')->assertOk()->assertJsonStructure(['data']);
    }

    public function test_history_returns_multiple_outputs_without_lazy_loading_trial_inspection(): void
    {
        $this->wo->outputs()->create(['good_count' => 1, 'reject_count' => 0, 'recorded_by' => $this->worker->id, 'recorded_at' => now(), 'batch_code' => 'FIRST']);
        $this->wo->outputs()->create(['good_count' => 1, 'reject_count' => 0, 'recorded_by' => $this->worker->id, 'recorded_at' => now(), 'batch_code' => 'SECOND']);
        Model::preventLazyLoading();
        try {
            $this->actingAs($this->manager)->getJson('/api/v1/production/work-orders/'.$this->wo->hash_id)
                ->assertOk()->assertJsonCount(2, 'data.outputs');
        } finally {
            Model::preventLazyLoading(false);
        }
    }

    public function test_paused_startup_can_resume_without_bypassing_trial_qc(): void
    {
        $this->inspection->update(['startup_trial_required' => true]);
        $this->wo->update(['status' => 'paused', 'pause_reason' => 'Setup paused']);
        $this->wo->machine->update(['status' => 'idle', 'current_work_order_id' => null]);
        $this->wo->mold->update(['status' => 'available', 'current_work_order_id' => null]);
        $readiness = app(WorkOrderService::class)->executionReadiness($this->wo->fresh());
        $this->assertFalse(collect($readiness['blockers'])->contains('code', 'quality'));
        $resumed = app(WorkOrderService::class)->resume($this->wo->fresh(), $this->manager->id);
        $this->assertSame('in_progress', $resumed->status->value);
        $this->expectException(BusinessRuleException::class);
        $resumed->assertProcessQualityReleased();
    }

    public function test_backfill_marks_prepared_draft_without_inventing_physical_evidence(): void
    {
        $spec = app(InspectionSpecService::class)->upsertForProduct($this->wo->product_id, [
            ['parameter_name' => 'Appearance', 'parameter_type' => 'visual', 'acceptance_criterion' => 'No damage', 'is_critical' => false],
        ], $this->manager->id);
        $this->inspection->update(['startup_trial_required' => false, 'inspection_spec_id' => $spec->id, 'inspection_spec_revision_id' => $spec->load('currentRevision')->currentRevision->id,
            'started_at' => now(), 'inspector_id' => $this->manager->id]);
        $migration = file_get_contents(database_path('migrations/2026_10_08_232000_add_startup_trial_production_ledger.php'));
        preg_match('/DB::statement\("(UPDATE inspections.*?)"\);/s', $migration, $matches);
        DB::statement($matches[1]);
        $this->assertTrue($this->inspection->fresh()->startup_trial_required);
        $this->assertSame(0, (int) $this->wo->fresh()->quantity_produced);
        $this->assertDatabaseCount('work_order_outputs', 0);
    }

    private function handover(): void
    {
        MaterialHandover::create([
            'stock_movement_id' => $this->issue->id, 'received_by_employee_id' => $this->worker->employee_id,
            'recipient_name' => $this->worker->employee->full_name, 'recorded_by' => $this->manager->id,
            'quantity' => $this->issue->quantity, 'handed_over_at' => now(),
        ]);
    }
}
