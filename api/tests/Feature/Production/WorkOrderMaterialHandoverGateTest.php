<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialHandover;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\ProductionLogEvent;
use App\Modules\Production\Enums\WoOperationStatus;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Models\ProductionLog;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WoOperation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderMaterial;
use App\Modules\Production\Resources\WoOperationResource;
use App\Modules\Production\Resources\WorkOrderOutputResource;
use App\Modules\Production\Services\WoOperationService;
use App\Modules\Production\Services\WorkOrderMaterialHandoverService;
use App\Modules\Production\Services\WorkOrderOutputService;
use App\Modules\Production\Services\WorkOrderService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\HasAssignedProductionCrew;
use Tests\Support\HasPassedInProcessQc;
use Tests\TestCase;

class WorkOrderMaterialHandoverGateTest extends TestCase
{
    use HasAssignedProductionCrew;
    use HasPassedInProcessQc;
    use RefreshDatabase;

    private User $worker;

    private User $manager;

    private WorkOrder $workOrder;

    private StockMovement $source;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfDay()->addHours(8));
        $this->seed(RolePermissionSeeder::class);
        $this->worker = User::factory()->create();
        $this->manager = User::factory()->withRole('production_manager')->create();
        $machine = Machine::factory()->create();
        $this->workOrder = WorkOrder::factory()->create([
            'status' => 'in_progress', 'quantity_target' => 100,
            'planned_start' => now(), 'planned_end' => now()->addHours(2),
            'actual_start' => now(), 'machine_id' => $machine->id,
        ]);
        $mold = Mold::create([
            'mold_code' => 'HO-'.uniqid(), 'name' => 'Handover gate mold',
            'product_id' => $this->workOrder->product_id, 'cavity_count' => 1,
            'cycle_time_seconds' => 30, 'output_rate_per_hour' => 100,
            'setup_time_minutes' => 10, 'current_shot_count' => 0,
            'max_shots_before_maintenance' => 100000, 'lifetime_max_shots' => 1000000,
            'status' => 'in_use', 'current_work_order_id' => $this->workOrder->id,
        ]);
        $mold->compatibleMachines()->sync([$machine->id]);
        $machine->update(['status' => 'running', 'current_work_order_id' => $this->workOrder->id]);
        $this->workOrder->update(['mold_id' => $mold->id]);
        ProductionSchedule::create([
            'work_order_id' => $this->workOrder->id, 'machine_id' => $machine->id, 'mold_id' => $mold->id,
            'scheduled_start' => now(), 'scheduled_end' => now()->addHours(2), 'priority_order' => 0,
            'status' => 'confirmed', 'is_confirmed' => true, 'reserves_capacity' => true,
        ]);
        $this->assignProductionCrew($this->workOrder, $this->worker);
        $this->passInProcessQc($this->workOrder);
        $defect = DefectType::create(['code' => 'HANDOVER', 'name' => 'Gate test defect', 'is_active' => true]);
        $this->payload = $this->outputForCrew($this->workOrder, [
            'good_count' => 0, 'reject_count' => 1,
            'defects' => [['defect_type_id' => $defect->id, 'count' => 1]],
        ], $this->worker);
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();
        WorkOrderMaterial::create([
            'work_order_id' => $this->workOrder->id, 'item_id' => $item->id,
            'bom_quantity' => 10, 'standard_cost' => 10, 'actual_quantity_issued' => 30, 'actual_cost' => 30,
        ]);
        $this->source = StockMovement::create([
            'item_id' => $item->id, 'from_location_id' => $location->id,
            'movement_type' => 'material_issue', 'quantity' => 30, 'unit_cost' => 1, 'total_cost' => 30,
            'reference_type' => 'work_order', 'reference_id' => $this->workOrder->id, 'created_at' => now(),
        ]);
    }

    public function test_pending_handover_blocks_normal_work_order_output_without_writes(): void
    {
        try {
            app(WorkOrderOutputService::class)->record($this->workOrder, $this->payload, $this->worker->id);
            $this->fail('Pending Warehouse handover must block output.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('Warehouse handover is pending', $error->getMessage());
        }

        $this->assertSame(0, $this->workOrder->outputs()->count());
        $this->assertSame(0, (int) $this->workOrder->fresh()->quantity_produced);
        $this->assertSame(0, (int) $this->workOrder->mold->fresh()->current_shot_count);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_pending_handover_blocks_operation_output_without_logs_or_totals(): void
    {
        $operation = $this->operation();
        $this->actingAs($this->worker);
        try {
            app(WoOperationService::class)->recordOutput($operation, 1);
            $this->fail('Operation output must share the Warehouse handover gate.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('Warehouse handover is pending', $error->getMessage());
        }
        $this->assertSame('0.0000', $operation->fresh()->qty_completed);
        $this->assertDatabaseCount('production_logs', 0);
    }

    public function test_manager_exception_saves_pending_sources_and_exact_replay_survives_terminal_order(): void
    {
        $data = $this->payload + [
            'material_handover_acknowledged' => true,
            'material_handover_reason' => 'Warehouse delivered resin; its receipt is waiting for Carlos.',
        ];
        $this->actingAs($this->manager);
        $output = app(WorkOrderOutputService::class)->record($this->workOrder, $data, $this->manager->id, 'handover-exception');
        $snapshot = $output->fresh()->material_handover_exception;
        $this->assertSame($this->manager->hash_id, $snapshot['manager_id']);
        $this->assertSame($this->manager->name, $snapshot['manager_name']);
        $this->assertSame($data['material_handover_reason'], $snapshot['reason']);
        $this->assertSame(now()->toIso8601String(), $snapshot['recorded_at']);
        $this->assertSame([['movement_id' => $this->source->hash_id, 'remaining_quantity' => '30.000']], $snapshot['sources']);
        $this->assertSame($snapshot, (new WorkOrderOutputResource($output))->resolve()['material_handover_exception']);
        $this->assertSame(['total' => 1, 'handed_over' => 0, 'pending' => 1], app(WorkOrderMaterialHandoverService::class)->summary($this->workOrder));
        $this->assertDatabaseCount('material_handovers', 0);

        Cache::flush();
        $this->workOrder->update(['status' => 'completed']);
        $replay = app(WorkOrderOutputService::class)->record($this->workOrder, $data, $this->manager->id, 'handover-exception');
        $this->assertSame($output->id, $replay->id);
        $this->assertSame(1, $this->workOrder->outputs()->count());

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('different production output payload');
        app(WorkOrderOutputService::class)->record($this->workOrder, array_replace($data, ['material_handover_reason' => 'Different receipt claim.']), $this->manager->id, 'handover-exception');
    }

    public function test_legacy_replay_succeeds_after_supplemental_issue_and_terminal_order(): void
    {
        $this->confirmSource();
        $output = app(WorkOrderOutputService::class)->record($this->workOrder, $this->payload, $this->worker->id, 'legacy-handover');
        $this->assertNull($output->material_handover_exception);
        $this->source->replicate()->save();
        Cache::flush();
        $this->workOrder->update(['status' => 'completed']);
        $replay = app(WorkOrderOutputService::class)->record($this->workOrder, $this->payload, $this->worker->id, 'legacy-handover');
        $this->assertSame($output->id, $replay->id);
    }

    public function test_exception_requires_both_permissions_active_user_acknowledgement_and_specific_reason(): void
    {
        $service = app(WorkOrderMaterialHandoverService::class);
        foreach (['employee', 'warehouse_staff', 'hr_staff', 'sales_staff'] as $role) {
            $actor = User::factory()->withRole($role)->create();
            $this->assertOutputExceptionDenied($service, $actor, true, 'Materials physically received.');
        }
        foreach (['production.wo.record', 'production.work_orders.lifecycle'] as $index => $permission) {
            $role = Role::create(['name' => 'Single permission role', 'slug' => 'single_handover_'.$index]);
            $role->permissions()->attach(Permission::query()->where('slug', $permission)->value('id'));
            $actor = User::factory()->create(['role_id' => $role->id]);
            $this->assertOutputExceptionDenied($service, $actor, true, 'Materials physically received.');
        }
        $inactive = User::factory()->withRole('production_manager')->create(['is_active' => false]);
        $this->assertOutputExceptionDenied($service, $inactive, true, 'Materials physically received.');
        $this->assertOutputExceptionDenied($service, $this->manager, false, 'Materials physically received.');
        $this->assertOutputExceptionDenied($service, $this->manager, true, '   ');
        $this->assertOutputExceptionDenied($service, $this->manager, true, str_repeat('x', 501));
        $this->assertOutputExceptionDenied($service, $this->manager, 'false', 'Materials physically received.');
    }

    public function test_employee_cannot_forge_manager_exception_in_shared_output_service(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Warehouse handover is pending');
        app(WorkOrderOutputService::class)->record($this->workOrder, $this->payload + [
            'material_handover_acknowledged' => true, 'material_handover_reason' => 'Claimed manager exception.',
        ], $this->worker->id);
    }

    public function test_output_requests_validate_explicit_acknowledgement_and_reason(): void
    {
        $data = $this->payload;
        $data['crew_id'] = app('hashids')->encode($data['crew_id']);
        $data['defects'][0]['defect_type_id'] = app('hashids')->encode($data['defects'][0]['defect_type_id']);
        $url = '/api/v1/production/work-orders/'.$this->workOrder->hash_id.'/outputs';
        $this->actingAs($this->manager)->postJson($url, $data + ['material_handover_acknowledged' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('material_handover_reason');
        $this->postJson($url, $data + ['material_handover_acknowledged' => 'false', 'material_handover_reason' => 'Claim'])
            ->assertUnprocessable()->assertJsonValidationErrors('material_handover_acknowledged');
        $this->postJson($url, $data + ['material_handover_acknowledged' => true, 'material_handover_reason' => str_repeat('x', 501)])
            ->assertUnprocessable()->assertJsonValidationErrors('material_handover_reason');
        $operation = $this->operation();
        $this->postJson('/api/v1/production/operations/'.$operation->hash_id.'/output', ['qty' => 1, 'material_handover_acknowledged' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('material_handover_reason');
        $this->assertSame(0, $this->workOrder->outputs()->count());
        $this->assertDatabaseCount('production_logs', 0);
    }

    public function test_operation_exception_records_actual_manager_in_log_snapshot(): void
    {
        $operation = $this->operation();
        $this->actingAs($this->manager);
        app(WoOperationService::class)->recordOutput($operation, 1, 0, null, null, [
            'material_handover_acknowledged' => true,
            'material_handover_reason' => 'Crew received material before Warehouse saved the receipt.',
        ]);
        $log = ProductionLog::query()->where('event_type', ProductionLogEvent::RecordOutput->value)->firstOrFail();
        $this->assertSame($this->worker->employee_id, $log->operator_id);
        $this->assertSame($this->manager->hash_id, $log->material_handover_exception['manager_id']);
        $resource = (new WoOperationResource($operation->fresh(['logs.operator'])))->resolve();
        $this->assertSame($log->material_handover_exception, $resource['logs'][0]['material_handover_exception']);
        $this->assertSame('1.0000', $operation->fresh()->qty_completed);
        $this->assertDatabaseCount('material_handovers', 0);
        $this->assertDatabaseHas('audit_logs', ['model_type' => ProductionLog::class, 'model_id' => $log->id, 'user_id' => $this->manager->id]);
    }

    public function test_exception_preserves_qc_guard(): void
    {
        $this->passInProcessQc($this->workOrder)->update(['status' => 'draft']);
        $this->actingAs($this->manager);
        try {
            app(WorkOrderOutputService::class)->record($this->workOrder, $this->payload + [
                'material_handover_acknowledged' => true, 'material_handover_reason' => 'Material received.',
            ], $this->manager->id);
            $this->fail('A handover exception cannot release process QC.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('QC', $error->getMessage());
        }
        $this->assertSame(0, $this->workOrder->outputs()->count());
    }

    public function test_exception_preserves_material_coverage_guard(): void
    {
        $this->workOrder->materials()->update(['actual_quantity_issued' => 0, 'actual_cost' => 0]);
        $this->actingAs($this->manager);
        try {
            app(WorkOrderOutputService::class)->record($this->workOrder, $this->payload + [
                'material_handover_acknowledged' => true, 'material_handover_reason' => 'Material received.',
            ], $this->manager->id);
            $this->fail('A receipt acknowledgement cannot cover unissued material.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('Material coverage is short', $error->getMessage());
        }
        $this->assertSame(0, $this->workOrder->outputs()->count());
        $this->assertDatabaseCount('material_handovers', 0);
    }

    public function test_exception_does_not_replace_existing_attendance_recording_reason(): void
    {
        $this->worker->employee->attendances()->update(['time_out' => now()]);
        $this->actingAs($this->manager);
        $data = $this->payload + [
            'material_handover_acknowledged' => true, 'material_handover_reason' => 'Material received.',
        ];
        unset($data['recording_reason']);
        try {
            app(WorkOrderOutputService::class)->record($this->workOrder, $data, $this->manager->id);
            $this->fail('Material acknowledgement must not bypass attendance reporting rules.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('recording reason', $error->getMessage());
        }
        $this->assertSame(0, $this->workOrder->outputs()->count());
    }

    public function test_completion_requires_resolved_handover_even_after_manager_exception(): void
    {
        $this->actingAs($this->manager);
        app(WorkOrderOutputService::class)->record($this->workOrder, $this->payload + [
            'material_handover_acknowledged' => true, 'material_handover_reason' => 'Material received.',
        ], $this->manager->id);
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Confirm material handover before completing');
        app(WorkOrderService::class)->complete($this->workOrder->fresh(), 'Run ended after one reject.');
    }

    public function test_fully_returned_sources_do_not_block_output_and_supplemental_issue_does(): void
    {
        $this->confirmSource();
        $returned = $this->source->replicate();
        $returned->save();
        StockMovement::create([
            'item_id' => $returned->item_id, 'to_location_id' => $returned->from_location_id,
            'movement_type' => 'material_return', 'quantity' => 30, 'unit_cost' => 1, 'total_cost' => 30,
            'reference_type' => 'stock_movement', 'reference_id' => $returned->id, 'created_at' => now(),
        ]);
        $service = app(WorkOrderMaterialHandoverService::class);
        $this->assertSame(['total' => 1, 'handed_over' => 1, 'pending' => 0], $service->summary($this->workOrder));
        $this->assertNull($service->assertForOutput($this->workOrder, $this->worker, []));
        $extra = $this->source->replicate();
        $extra->save();
        $this->assertSame(['total' => 2, 'handed_over' => 1, 'pending' => 1], $service->summary($this->workOrder));
        $this->expectException(BusinessRuleException::class);
        $service->assertForOutput($this->workOrder, $this->worker, []);
    }

    private function assertOutputExceptionDenied(WorkOrderMaterialHandoverService $service, User $actor, mixed $acknowledged, string $reason): void
    {
        try {
            $service->assertForOutput($this->workOrder, $actor, [
                'material_handover_acknowledged' => $acknowledged, 'material_handover_reason' => $reason,
                'recording_reason' => 'Generic reason must never acknowledge receipt.',
            ]);
            $this->fail('Invalid or unauthorized acknowledgement must never bypass handover.');
        } catch (BusinessRuleException) {
            $this->assertDatabaseCount('material_handovers', 0);
        }
    }

    private function operation(): WoOperation
    {
        return WoOperation::create([
            'work_order_id' => $this->workOrder->id, 'operator_id' => $this->worker->employee_id,
            'sequence' => 1, 'operation_name' => 'Injection', 'status' => WoOperationStatus::InProgress,
            'qty_planned' => 100, 'qty_completed' => 0, 'qty_scrapped' => 0, 'downtime_minutes' => 0,
        ]);
    }

    private function confirmSource(?StockMovement $source = null): void
    {
        $source ??= $this->source;
        MaterialHandover::create([
            'stock_movement_id' => $source->id, 'received_by_employee_id' => $this->worker->employee_id,
            'recipient_name' => $this->worker->employee->full_name, 'recorded_by' => $this->manager->id,
            'quantity' => $source->quantity, 'handed_over_at' => now(),
        ]);
    }
}
