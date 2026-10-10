<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderAssignmentService;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\InspectionSpecService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HasAssignedProductionCrew;
use Tests\TestCase;

class WorkOrderStartQcGateTest extends TestCase
{
    use HasAssignedProductionCrew;
    use RefreshDatabase;

    private User $manager;

    private Product $product;

    private Machine $machine;

    private Mold $mold;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->manager = User::factory()->withRole('production_manager')->create(['is_active' => true]);
        $this->actingAs($this->manager);
        $this->product = Product::factory()->create();
        $this->machine = Machine::factory()->create(['status' => 'idle', 'current_work_order_id' => null]);
        $this->mold = Mold::create([
            'mold_code' => 'START-QC-'.strtoupper(substr(uniqid(), -5)),
            'name' => 'Start QC gate mold',
            'product_id' => $this->product->id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 100,
            'setup_time_minutes' => 10,
            'status' => 'available',
            'current_shot_count' => 0,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 1000000,
        ]);
        $this->mold->compatibleMachines()->syncWithoutDetaching([$this->machine->id]);
    }

    private function plannedWorkOrder(): WorkOrder
    {
        $workOrder = WorkOrder::create([
            'wo_number' => 'WO-START-QC-'.strtoupper(substr(uniqid(), -6)),
            'product_id' => $this->product->id,
            'machine_id' => $this->machine->id,
            'mold_id' => $this->mold->id,
            'status' => WorkOrderStatus::Planned->value,
            'quantity_target' => 10,
            'quantity_produced' => 0,
            'quantity_good' => 0,
            'quantity_rejected' => 0,
            'planned_start' => now()->addHour(),
            'planned_end' => now()->addDays(2),
            'work_order_class' => 'non_stock',
            'exception_reason' => 'QC gate test work order',
            'exception_authorized_by' => $this->manager->id,
            'created_by' => $this->manager->id,
        ]);
        $this->assignProductionCrew($workOrder, $this->manager);

        return $workOrder;
    }

    private function confirmedWorkOrder(): WorkOrder
    {
        $confirmed = app(WorkOrderService::class)->confirm($this->plannedWorkOrder());
        $this->travelTo($confirmed->planned_start);

        return $confirmed;
    }

    private function startPayload(WorkOrder $workOrder): array
    {
        $crew = app(WorkOrderAssignmentService::class)->currentCrew($workOrder);

        return [
            'expected_crew_id' => $crew->hash_id,
            'presence_confirmed_employee_ids' => [app('hashids')->encode($crew->recorder_employee_id)],
        ];
    }

    private function startSnapshot(WorkOrder $workOrder): array
    {
        return [
            'work_order' => $workOrder->fresh()->getRawOriginal(),
            'machine' => $this->machine->fresh()->getRawOriginal(),
            'mold' => $this->mold->fresh()->getRawOriginal(),
            'crews' => $workOrder->crews()->with('members')->get()->map(fn ($crew) => [
                'crew' => $crew->getRawOriginal(),
                'members' => $crew->members->map(fn ($member) => $member->getRawOriginal())->all(),
            ])->all(),
            'schedules' => $workOrder->schedules()->orderBy('id')->get()
                ->map(fn ($schedule) => $schedule->getRawOriginal())->all(),
            'inspections' => DB::table('inspections')->count(),
            'stock_movements' => DB::table('stock_movements')->count(),
            'audit_logs' => DB::table('audit_logs')->count(),
            'event_outbox' => DB::table('event_outbox')->count(),
            'chain_step_runs' => DB::table('chain_step_runs')->count(),
        ];
    }

    public function test_start_rejects_planned_work_order_even_with_a_valid_crew_before_any_writes(): void
    {
        $workOrder = $this->plannedWorkOrder();
        $crew = app(WorkOrderAssignmentService::class)->currentCrew($workOrder);
        $before = $this->startSnapshot($workOrder);

        $this->postJson("/api/v1/production/work-orders/{$workOrder->hash_id}/start", [
            'expected_crew_id' => $crew->hash_id,
        ])->assertStatus(409)->assertJsonPath('code', 'ILLEGAL_WORK_ORDER_TRANSITION');

        $this->assertSame($before, $this->startSnapshot($workOrder));
    }

    public function test_start_rolls_back_when_required_in_process_qc_cannot_be_created(): void
    {
        $workOrder = $this->confirmedWorkOrder();
        $before = $this->startSnapshot($workOrder);

        $this->postJson("/api/v1/production/work-orders/{$workOrder->hash_id}/start", $this->startPayload($workOrder))
            ->assertUnprocessable()
            ->assertJsonPath('message', fn ($message): bool => is_string($message) && str_contains($message, 'inspection spec'));

        $this->assertSame($before, $this->startSnapshot($workOrder));
        $this->assertSame(0, Inspection::query()
            ->where('entity_id', $workOrder->id)
            ->where('stage', InspectionStage::InProcess->value)
            ->count());
    }

    public function test_start_persists_process_qc_before_returning_success(): void
    {
        app(InspectionSpecService::class)->upsertForProduct($this->product->id, [
            ['parameter_name' => 'Surface condition', 'parameter_type' => 'visual', 'is_critical' => false],
        ], $this->manager->id);
        $workOrder = $this->confirmedWorkOrder();

        $this->postJson("/api/v1/production/work-orders/{$workOrder->hash_id}/start", $this->startPayload($workOrder))
            ->assertOk()
            ->assertJsonPath('data.status', WorkOrderStatus::InProgress->value)
            ->assertJsonCount(1, 'data.inspections')
            ->assertJsonPath('data.inspections.0.stage', InspectionStage::InProcess->value);

        $this->assertDatabaseHas('inspections', [
            'stage' => InspectionStage::InProcess->value,
            'entity_type' => 'work_order',
            'entity_id' => $workOrder->id,
            'wo_operation_id' => null,
        ]);
        $this->assertSame(WorkOrderStatus::InProgress, $workOrder->fresh()->status);
        $this->assertSame('running', $this->machine->fresh()->status->value);
        $this->assertSame('in_use', $this->mold->fresh()->status->value);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->manager->id,
            'model_type' => WorkOrder::class,
            'model_id' => $workOrder->id,
            'action' => 'updated',
        ]);
        $this->assertDatabaseHas('chain_step_runs', [
            'entity_type' => 'work_order',
            'entity_id' => $workOrder->id,
            'step' => WorkOrderStatus::InProgress->value,
        ]);
        $this->assertSame(1, DB::table('notifications')
            ->where('type', 'chain.in_process_qc_required')
            ->where('notifiable_id', $this->manager->id)
            ->count());
    }

    public function test_passed_single_actor_process_qc_releases_work_order_gate(): void
    {
        app(InspectionSpecService::class)->upsertForProduct($this->product->id, [
            ['parameter_name' => 'Surface condition', 'parameter_type' => 'visual', 'is_critical' => false],
        ], $this->manager->id);
        $workOrder = $this->confirmedWorkOrder();
        $started = $this->postJson("/api/v1/production/work-orders/{$workOrder->hash_id}/start", $this->startPayload($workOrder))
            ->assertOk();
        $inspectionId = $started->json('data.inspections.0.id');
        $inspection = Inspection::query()->where('inspection_number', $started->json('data.inspections.0.inspection_number'))->firstOrFail();
        $items = $inspection->measurements()->get()->map(static fn ($measurement): array => [
            'id' => $measurement->hash_id,
            'result' => 'passed',
        ])->all();
        $qcInspector = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);

        $this->actingAs($qcInspector)
            ->postJson("/api/v1/quality/inspections/{$inspectionId}/item-results", [
                'items' => $items,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', InspectionStatus::InProgress->value);
        $this->assertSame(InspectionStatus::InProgress, $inspection->fresh()->status);
        $this->assertSame(WorkOrderStatus::InProgress, $workOrder->fresh()->status);

        $this->postJson("/api/v1/quality/inspections/{$inspectionId}/batch-result", [
            'result' => 'passed',
            'coverage_confirmed' => true,
            'inspected_quantity' => $inspection->sample_size,
            'sample_defect_count' => 0,
            'critical_defect' => false,
            'defects_removed_confirmed' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', InspectionStatus::Passed->value);

        $this->assertNull($inspection->fresh()->reviewed_by);
        $workOrder->fresh()->assertProcessQualityReleased();
    }
}
