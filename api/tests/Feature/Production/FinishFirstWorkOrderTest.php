<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\OutboxMessage;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Controllers\MaterialHandoverController;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\MovementGlPostingService;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Events\WorkOrderCompleted;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WoOperation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WoOperationService;
use App\Modules\Production\Services\WorkOrderAssignmentService;
use App\Modules\Production\Services\WorkOrderOutputService;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Quality\Listeners\TriggerOutgoingQC;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Services\QualityStockHoldService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Support\HasAssignedProductionCrew;
use Tests\TestCase;

class FinishFirstWorkOrderTest extends TestCase
{
    use HasAssignedProductionCrew, RefreshDatabase;

    private User $manager;

    private WorkOrder $wo;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-10-08 09:00:00', config('app.timezone')));
        config(['inspection.finish_first_wo_enabled' => true]);
        $this->seed([RolePermissionSeeder::class, ChartOfAccountsSeeder::class, SettingsSeeder::class]);
        $this->manager = User::factory()->withRole('production_manager')->create();
        $machine = Machine::factory()->create();
        $this->wo = WorkOrder::factory()->create(['machine_id' => $machine->id, 'quantity_target' => 200, 'status' => 'in_progress',
            'actual_start' => now(), 'created_by' => $this->manager->id, 'planned_start' => now(), 'planned_end' => now()->addDay()]);
        $mold = Mold::create(['mold_code' => 'FF-MOLD', 'name' => 'Finish first test mold', 'product_id' => $this->wo->product_id, 'cavity_count' => 1, 'cycle_time_seconds' => 30, 'output_rate_per_hour' => 100, 'setup_time_minutes' => 10,
            'current_shot_count' => 0, 'max_shots_before_maintenance' => 100000, 'lifetime_max_shots' => 1000000,
            'status' => 'in_use', 'current_work_order_id' => $this->wo->id]);
        $mold->compatibleMachines()->sync([$machine->id]);
        $machine->update(['status' => 'running', 'current_work_order_id' => $this->wo->id]);
        $this->wo->update(['mold_id' => $mold->id]);
        ProductionSchedule::create(['work_order_id' => $this->wo->id, 'machine_id' => $machine->id, 'mold_id' => $mold->id,
            'scheduled_start' => now(), 'scheduled_end' => now()->addHours(2), 'status' => 'confirmed', 'is_confirmed' => true,
            'reserves_capacity' => true, 'priority_order' => 0]);
        $this->assignProductionCrew($this->wo, $this->manager);
        $this->actingAs($this->manager);
    }

    private function recordTargetWithSettledReceipts(): Carbon
    {
        $this->wo->update(['actual_start' => now()->subMinutes(15)]);
        $service = app(WorkOrderOutputService::class);
        $first = $service->record($this->wo, $this->outputForCrew($this->wo, ['produced_count' => 100], $this->manager), $this->manager->id, 'ff-time-first');
        $second = $service->record($this->wo, $this->outputForCrew($this->wo, ['produced_count' => 100], $this->manager), $this->manager->id, 'ff-time-second');
        $recordedAt = Carbon::parse($this->wo->outputs()->max('recorded_at'));

        $zone = WarehouseZone::factory()->create(['zone_type' => 'finished_goods']);
        $location = WarehouseLocation::factory()->create(['zone_id' => $zone->id]);
        $item = Item::factory()->create(['code' => $this->wo->product->part_number, 'item_type' => 'finished_good']);
        foreach ([$first, $second] as $output) {
            $receipt = StockMovement::create(['movement_type' => 'production_receipt', 'item_id' => $item->id,
                'quantity' => '100.000', 'unit_cost' => '5.00', 'total_cost' => '500.00', 'to_location_id' => $location->id,
                'reference_type' => 'work_order_output', 'reference_id' => $output->id, 'created_by' => $this->manager->id, 'lot_number' => $output->batch_code]);
            app(MovementGlPostingService::class)->postFor($receipt);
            $output->forceFill(['production_receipt_movement_id' => $receipt->id, 'production_receipt_handoff_status' => 'generated'])->save();
        }

        return $recordedAt;
    }

    private function prepareAutomaticStockReceipt(): array
    {
        Uom::firstOrCreate(['code' => 'PCS'], ['name' => 'Pieces']);
        $zone = WarehouseZone::factory()->create(['zone_type' => 'finished_goods']);
        $location = WarehouseLocation::factory()->create(['zone_id' => $zone->id, 'is_active' => true, 'is_blocked' => false]);
        $item = Item::factory()->create(['code' => $this->wo->product->part_number, 'item_type' => 'finished_good', 'unit_of_measure' => 'pcs']);
        $this->wo->product->update(['standard_cost' => '5.00', 'unit_of_measure' => 'pcs']);

        return [$item, $location];
    }

    public function test_last_recorded_batch_automatically_finishes_without_releasing_quality_hold(): void
    {
        [$item, $location] = $this->prepareAutomaticStockReceipt();
        $service = app(WorkOrderOutputService::class);
        $service->record($this->wo, $this->outputForCrew($this->wo, ['produced_count' => 100], $this->manager), $this->manager->id, 'auto-first');
        $this->assertSame('in_progress', $this->wo->fresh()->status->value);
        $secondData = $this->outputForCrew($this->wo, ['produced_count' => 100], $this->manager);
        $second = $service->record($this->wo->fresh(), $secondData, $this->manager->id, 'auto-second');

        $this->assertSame('completed', $this->wo->fresh()->status->value);
        $this->assertTrue($this->wo->fresh()->actual_end->equalTo(now()));
        $this->assertNull($this->wo->machine->fresh()->current_work_order_id);
        $this->assertNull($this->wo->mold->fresh()->current_work_order_id);
        $this->assertSame('executed', $this->wo->schedules()->first()->status->value);
        $this->assertSame('200.000', app(QualityStockHoldService::class)->heldQuantity($item->id, $location->id));
        $this->assertNotNull(app(WorkOrderService::class)->qualityCloseBlocker($this->wo->fresh()));
        $this->assertSame($second->id, $service->record($this->wo->fresh(), $secondData, $this->manager->id, 'auto-second')->id);
        $this->assertSame(2, $this->wo->outputs()->count());
        $this->assertSame(200, (int) $this->wo->fresh()->quantity_produced);
        $this->assertSame(1, OutboxMessage::where('event_type', WorkOrderCompleted::class)->count());
        $spec = InspectionSpec::create(['product_id' => $this->wo->product_id,
            'version' => 1, 'is_active' => true, 'created_by' => $this->manager->id]);
        InspectionSpecItem::create(['inspection_spec_id' => $spec->id,
            'parameter_name' => 'Appearance', 'parameter_type' => 'visual', 'acceptance_criterion' => 'No damage', 'is_critical' => false]);
        $listener = app(TriggerOutgoingQC::class);
        $listener->handle(new WorkOrderCompleted($this->wo->fresh()));
        $listener->handle(new WorkOrderCompleted($this->wo->fresh()));
        $this->assertSame(2, $this->wo->inspections()->where('stage', 'outgoing')->count());
        $this->assertSame('200.000', app(QualityStockHoldService::class)->heldQuantity($item->id, $location->id));
    }

    public function test_assigned_worker_final_batch_automatically_finishes(): void
    {
        $this->prepareAutomaticStockReceipt();
        $crew = app(WorkOrderAssignmentService::class)->currentCrew($this->wo);
        $worker = User::where('employee_id', $crew->recorder_employee_id)->firstOrFail();
        $this->actingAs($worker);
        $response = $this->withHeader('X-Idempotency-Key', 'worker-auto-finish')->postJson('/api/v1/production/my-work-orders/'.$this->wo->hash_id.'/outputs', [
            'crew_id' => $crew->hash_id, 'produced_count' => 200,
        ]);
        $response->assertSuccessful();
        $this->assertSame('completed', $this->wo->fresh()->status->value);
    }

    public function test_finish_first_output_accepts_legacy_good_count_payload(): void
    {
        $this->prepareAutomaticStockReceipt();
        $crew = app(WorkOrderAssignmentService::class)->currentCrew($this->wo);

        $response = $this->withHeader('X-Idempotency-Key', 'finish-first-legacy-good-count')
            ->postJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/outputs', [
                'crew_id' => $crew->hash_id,
                'good_count' => 200,
                'reject_count' => 0,
                'defects' => [],
                'recording_reason' => 'Manager verifying legacy client payload',
            ]);
        $this->assertTrue($response->isSuccessful(), $response->getContent());

        $this->assertSame('completed', $this->wo->fresh()->status->value);
        $this->assertSame(200, (int) $this->wo->fresh()->quantity_produced);
        $this->assertSame(1, $this->wo->outputs()->count());
    }

    public function test_stock_receipt_recovery_finishes_target_without_recording_output_twice(): void
    {
        $service = app(WorkOrderOutputService::class);
        $output = $service->record($this->wo, $this->outputForCrew($this->wo, ['produced_count' => 200], $this->manager), $this->manager->id, 'auto-recovery');
        $this->assertSame('in_progress', $this->wo->fresh()->status->value);
        $this->assertStringContainsString('Stock receipt pending', (string) app(WorkOrderService::class)->productionCompletionBlocker($this->wo->fresh()));
        $this->prepareAutomaticStockReceipt();
        $service->retryProductionReceipt($output, $this->manager);
        $this->assertSame('completed', $this->wo->fresh()->status->value);
        $service->retryProductionReceipt($output->fresh(), $this->manager);
        $this->assertSame(1, $this->wo->outputs()->count());
        $this->assertSame(200, (int) $this->wo->fresh()->quantity_produced);
        $this->assertSame(1, OutboxMessage::where('event_type', WorkOrderCompleted::class)->count());
    }

    public function test_final_routing_operation_unblocks_automatic_finish(): void
    {
        $this->prepareAutomaticStockReceipt();
        $operation = WoOperation::create([
            'work_order_id' => $this->wo->id, 'sequence' => 1, 'operation_name' => 'Packing',
            'status' => 'in_progress', 'qty_planned' => 200, 'qty_completed' => 200, 'qty_scrapped' => 0,
        ]);
        app(WorkOrderOutputService::class)->record($this->wo,
            $this->outputForCrew($this->wo, ['produced_count' => 200], $this->manager), $this->manager->id, 'auto-routing');
        $this->assertSame('in_progress', $this->wo->fresh()->status->value);
        $this->assertStringContainsString('routing operations', (string) app(WorkOrderService::class)->productionCompletionBlocker($this->wo->fresh()));
        app(WoOperationService::class)->completeOperation($operation);
        $this->assertSame('completed', $this->wo->fresh()->status->value);
    }

    public function test_material_handover_unblocks_automatic_finish_without_another_output(): void
    {
        $this->prepareAutomaticStockReceipt();
        $material = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();
        $this->wo->materials()->create(['item_id' => $material->id, 'bom_quantity' => '1.000', 'actual_quantity_issued' => '1.000']);
        $source = StockMovement::create(['movement_type' => 'material_issue', 'item_id' => $material->id,
            'quantity' => '1.000', 'unit_cost' => '0.00', 'total_cost' => '0.00', 'from_location_id' => $location->id,
            'reference_type' => 'work_order', 'reference_id' => $this->wo->id, 'created_by' => $this->manager->id]);
        app(WorkOrderOutputService::class)->record($this->wo,
            $this->outputForCrew($this->wo, ['produced_count' => 200, 'material_handover_acknowledged' => true,
                'material_handover_reason' => 'Warehouse confirmation is pending.'], $this->manager), $this->manager->id, 'auto-handover');
        $this->assertSame('in_progress', $this->wo->fresh()->status->value);
        $this->assertStringContainsString('Confirm material handover', (string) app(WorkOrderService::class)->productionCompletionBlocker($this->wo->fresh()));
        $crew = app(WorkOrderAssignmentService::class)->currentCrew($this->wo);
        $request = Request::create('/', 'POST', ['received_by_employee_id' => app('hashids')->encode($crew->recorder_employee_id), 'expected_quantity' => '1.000']);
        $request->setUserResolver(fn () => $this->manager);
        app(MaterialHandoverController::class)->store($request, $source);
        $this->assertSame('completed', $this->wo->fresh()->status->value);
        $this->assertSame(1, $this->wo->outputs()->count());
    }

    public function test_ledger_recovery_unblocks_automatic_finish_without_reposting_stock(): void
    {
        $this->prepareAutomaticStockReceipt();
        $settings = app(SettingsService::class);
        $originalAccount = $settings->requiredString('accounting.accounts.material_consumption_code');
        $settings->set('accounting.accounts.material_consumption_code', 'NO-SUCH-ACCOUNT');
        $output = app(WorkOrderOutputService::class)->record($this->wo,
            $this->outputForCrew($this->wo, ['produced_count' => 200], $this->manager), $this->manager->id, 'auto-ledger');
        $this->assertSame('in_progress', $this->wo->fresh()->status->value);
        $this->assertStringContainsString('ledger', strtolower((string) app(WorkOrderService::class)->productionCompletionBlocker($this->wo->fresh())));
        $settings->set('accounting.accounts.material_consumption_code', $originalAccount);
        app(MovementGlPostingService::class)->retry($output->productionReceiptMovement);
        $this->assertSame('completed', $this->wo->fresh()->status->value);
        $this->assertSame(1, StockMovement::where('movement_type', 'production_receipt')->count());
    }

    public function test_physical_output_needs_no_startup_qc_and_completion_keeps_stock_held(): void
    {
        $service = app(WorkOrderOutputService::class);
        $first = $service->record($this->wo, $this->outputForCrew($this->wo, ['produced_count' => 100], $this->manager), $this->manager->id, 'ff-first');
        $second = $service->record($this->wo, $this->outputForCrew($this->wo, ['produced_count' => 100], $this->manager), $this->manager->id, 'ff-second');
        $this->assertSame(0, Inspection::query()->count());
        $this->assertSame(200, (int) $this->wo->fresh()->quantity_produced);
        $this->assertSame(200, (int) $this->wo->mold->fresh()->current_shot_count);
        $zone = WarehouseZone::factory()->create(['zone_type' => 'finished_goods']);
        $location = WarehouseLocation::factory()->create(['zone_id' => $zone->id]);
        $item = Item::factory()->create(['code' => $this->wo->product->part_number, 'item_type' => 'finished_good']);
        foreach ([$first, $second] as $output) {
            $receipt = StockMovement::create(['movement_type' => 'production_receipt', 'item_id' => $item->id,
                'quantity' => '100.000', 'unit_cost' => '5.00', 'total_cost' => '500.00', 'to_location_id' => $location->id,
                'reference_type' => 'work_order_output', 'reference_id' => $output->id, 'created_by' => $this->manager->id, 'lot_number' => $output->batch_code]);
            app(MovementGlPostingService::class)->postFor($receipt);
            $output->forceFill(['production_receipt_movement_id' => $receipt->id, 'production_receipt_handoff_status' => 'generated'])->save();
        }
        $this->travel(10)->minutes();
        $completed = app(WorkOrderService::class)->complete($this->wo);
        $this->assertSame('completed', $completed->status->value);
        $this->assertNull($completed->machine->fresh()->current_work_order_id);
        $this->assertSame('200.000', app(QualityStockHoldService::class)->heldQuantity($item->id, $location->id));
        $this->assertSame(0, Inspection::query()->count());
        $this->assertStringContainsString('QC', (string) app(WorkOrderService::class)->qualityCloseBlocker($completed));
    }

    public function test_zero_output_cannot_finish_even_with_a_short_completion_reason(): void
    {
        try {
            app(WorkOrderService::class)->complete($this->wo, 'Run stopped before making any pieces.');
            $this->fail('An empty production run must not finish.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('no recorded physical output', $error->getMessage());
        }

        $this->assertSame('in_progress', $this->wo->fresh()->status->value);
        $this->assertSame(0, $this->wo->outputs()->count());
        $this->assertSame(0, Inspection::query()->count());
        $this->assertSame($this->wo->id, $this->wo->machine->fresh()->current_work_order_id);
    }

    public function test_manual_finish_cannot_claim_future_production(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Production finish time must be between the actual start, latest approved production finish, and current time.');
        $this->recordTargetWithSettledReceipts();
        $this->travel(10)->minutes();
        app(WorkOrderService::class)->complete($this->wo, null, now()->addMinute());
    }

    public function test_unresolved_stock_receipt_keeps_production_open_with_a_recovery_action(): void
    {
        $output = app(WorkOrderOutputService::class)->record($this->wo,
            $this->outputForCrew($this->wo, ['produced_count' => 200], $this->manager),
            $this->manager->id, 'ff-missing-stock-item');
        $this->assertSame('manual_required', $output->fresh()->production_receipt_handoff_status->value);

        try {
            app(WorkOrderService::class)->complete($this->wo->fresh());
            $this->fail('An unresolved stock receipt must block completion.');
        } catch (BusinessRuleException $error) {
            $this->assertSame('Stock receipt pending. Ask Warehouse to check inventory setup, then click Retry in Recent outputs.', $error->getMessage());
        }
        $this->assertSame('in_progress', $this->wo->fresh()->status->value);
        $this->assertSame(200, (int) $this->wo->fresh()->quantity_produced);
        $this->assertSame(1, $this->wo->outputs()->count());
        $this->assertSame(0, Inspection::query()->count());
    }

    public function test_manual_finish_cannot_precede_normal_output_recording(): void
    {
        $recordedAt = $this->recordTargetWithSettledReceipts();
        $this->travel(10)->minutes();
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Production finish time must be between the actual start, latest approved production finish, and current time.');
        app(WorkOrderService::class)->complete($this->wo, null, $recordedAt->copy()->subSecond());
    }

    public function test_manual_finish_within_execution_and_output_evidence_is_accepted(): void
    {
        $recordedAt = $this->recordTargetWithSettledReceipts();
        $this->travel(10)->minutes();
        $finishedAt = now()->subMinute();
        $this->assertTrue($finishedAt->gte($recordedAt));

        $completed = app(WorkOrderService::class)->complete($this->wo, null, $finishedAt);

        $this->assertSame('completed', $completed->status->value);
        $this->assertTrue($completed->actual_end->equalTo($finishedAt));
    }
}
