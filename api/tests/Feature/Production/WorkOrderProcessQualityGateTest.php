<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Support\HasAssignedProductionCrew;

class WorkOrderProcessQualityGateTest extends TestCase
{
    use RefreshDatabase;
    use HasAssignedProductionCrew;

    private User $producer;

    private User $inspector;

    private Product $product;

    private WorkOrder $workOrder;

    private DefectType $defectType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $roleId = Role::query()->where('slug', 'production_manager')->value('id');
        $this->producer = User::factory()->create(['role_id' => $roleId, 'is_active' => true]);
        $this->inspector = User::factory()->withRole('qc_inspector')->create(['is_active' => true]);
        $this->actingAs($this->producer);

        $this->product = Product::create([
            'part_number' => 'FG-QCG-'.strtoupper(substr(uniqid(), -6)),
            'name' => 'QC gate part',
            'unit_of_measure' => 'pcs',
            'standard_cost' => '5.00',
            'is_active' => true,
        ]);
        $machine = Machine::factory()->create();
        $mold = Mold::create([
            'mold_code' => 'QC-GATE-'.strtoupper(substr(uniqid(), -6)),
            'name' => 'QC gate test mold',
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
        $mold->compatibleMachines()->sync([$machine->id]);
        $this->workOrder = WorkOrder::create([
            'wo_number' => 'WO-QCG-'.strtoupper(substr(uniqid(), -6)),
            'product_id' => $this->product->id,
            'machine_id' => $machine->id,
            'mold_id' => $mold->id,
            'status' => WorkOrderStatus::InProgress->value,
            'quantity_target' => 10,
            'quantity_produced' => 0,
            'quantity_good' => 0,
            'quantity_rejected' => 0,
            'planned_start' => now()->subHour(),
            'planned_end' => now()->addHour(),
            'actual_start' => now()->subMinutes(30),
            'created_by' => $this->producer->id,
        ]);
        $machine->update(['status' => 'running', 'current_work_order_id' => $this->workOrder->id]);
        $mold->update(['status' => 'in_use', 'current_work_order_id' => $this->workOrder->id]);
        $this->assignProductionCrew($this->workOrder, $this->producer);
        $this->defectType = DefectType::create([
            'code' => 'QCG'.strtoupper(substr(uniqid(), -5)),
            'name' => 'QC gate test defect',
            'is_active' => true,
        ]);
    }

    private function recordRejectedPiece(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/production/work-orders/{$this->workOrder->hash_id}/outputs", [
                'crew_id' => app('hashids')->encode($this->assignProductionCrew($this->workOrder, $this->producer )->id),
                'recording_reason' => 'Production regression recording.',
            'good_count' => 0,
            'reject_count' => 1,
            'defects' => [[
                'defect_type_id' => $this->defectType->hash_id,
                'count' => 1,
            ]],
        ]);
    }

    private function processInspection(InspectionStatus $status, bool $makerChecked = false): Inspection
    {
        $checker = $makerChecked ? User::factory()->withRole('qc_inspector')->create(['is_active' => true]) : null;

        return Inspection::create([
            'inspection_number' => 'QC-QCG-'.strtoupper(substr(uniqid(), -6)),
            'stage' => InspectionStage::InProcess->value,
            'status' => $status->value,
            'product_id' => $this->product->id,
            'entity_type' => 'work_order',
            'entity_id' => $this->workOrder->id,
            'batch_quantity' => 10,
            'sample_size' => 1,
            'inspector_id' => $this->inspector->id,
            'reviewed_by' => $checker?->id,
            'reviewed_at' => $checker ? now() : null,
            'completed_at' => in_array($status, [InspectionStatus::Passed, InspectionStatus::Failed], true) ? now() : null,
        ]);
    }

    private function productionState(): array
    {
        $workOrder = $this->workOrder->fresh();
        $outputs = DB::table('work_order_outputs')
            ->where('work_order_id', $this->workOrder->id)
            ->orderBy('id')
            ->get();
        $outputIds = $outputs->pluck('id');

        return [
            'work_order' => [
                'status' => $workOrder->status?->value,
                'quantity_produced' => (int) $workOrder->quantity_produced,
                'quantity_good' => (int) $workOrder->quantity_good,
                'quantity_rejected' => (int) $workOrder->quantity_rejected,
                'scrap_rate' => (string) $workOrder->scrap_rate,
                'updated_at' => $workOrder->updated_at?->toISOString(),
            ],
            'outputs' => $outputs->toArray(),
            'defects' => DB::table('work_order_defects')
                ->whereIn('output_id', $outputIds)
                ->orderBy('id')
                ->get()
                ->toArray(),
            'receipt_movements' => DB::table('stock_movements')
                ->where('reference_type', 'work_order_output')
                ->whereIn('reference_id', $outputIds)
                ->orderBy('id')
                ->get()
                ->toArray(),
            'audit_logs' => DB::table('audit_logs')->count(),
            'event_outbox' => DB::table('event_outbox')->count(),
            'chain_step_runs' => DB::table('chain_step_runs')->count(),
        ];
    }

    private function assertOutputRejectedWithoutWrites(string $message): void
    {
        $before = $this->productionState();
        $response = $this->recordRejectedPiece()->assertUnprocessable();

        $this->assertStringContainsString($message, (string) $response->json('message'));
        $this->assertSame($before, $this->productionState());
    }

    public function test_output_is_blocked_while_start_qc_side_effect_is_missing(): void
    {
        $this->assertOutputRejectedWithoutWrites('has no active in-process QC inspection');
    }

    public function test_output_is_blocked_until_in_process_qc_has_a_final_result(): void
    {
        $inspection = $this->processInspection(InspectionStatus::AwaitingReview);

        $this->assertOutputRejectedWithoutWrites('waiting for in-process QC');

        $this->getJson("/api/v1/production/work-orders/{$this->workOrder->hash_id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['process_quality_blocker']])
            ->assertJsonPath('data.process_quality_blocker', fn ($blocker): bool => is_string($blocker) && str_contains($blocker, $inspection->inspection_number));
    }

    public function test_output_can_be_recorded_after_in_process_qc_passes(): void
    {
        $this->processInspection(InspectionStatus::Passed, makerChecked: true);

        $this->getJson("/api/v1/production/work-orders/{$this->workOrder->hash_id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['process_quality_blocker']])
            ->assertJsonPath('data.process_quality_blocker', null);

        $this->recordRejectedPiece()->assertCreated();

        $this->assertSame(1, $this->workOrder->outputs()->count());
        $this->assertSame(1, (int) $this->workOrder->fresh()->quantity_produced);
        $this->assertSame(1, (int) $this->workOrder->fresh()->quantity_rejected);
    }

    public function test_passed_status_without_an_independent_checker_cannot_release_output(): void
    {
        $this->processInspection(InspectionStatus::Passed);

        $this->assertOutputRejectedWithoutWrites('waiting for in-process QC');
    }
}
