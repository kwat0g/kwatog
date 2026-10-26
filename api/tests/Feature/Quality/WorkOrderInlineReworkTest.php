<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\OutboxEventCodec;
use App\Common\Services\OutboxService;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\MovementGlPostingService;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Services\MrpEngineService;
use App\Modules\Production\Events\WorkOrderQualityResolved;
use App\Modules\Production\Listeners\AutoCloseResolvedWorkOrder;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Production\Services\ProductionResourceService;
use App\Modules\Production\Services\WorkOrderAssignmentService;
use App\Modules\Production\Services\WorkOrderReworkService;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Services\InspectionSpecService;
use App\Modules\Quality\Services\NcrService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\HasAssignedProductionCrew;
use Tests\Support\HasPassedInProcessQc;
use Tests\TestCase;

class WorkOrderInlineReworkTest extends TestCase
{
    use HasAssignedProductionCrew;
    use HasPassedInProcessQc;
    use RefreshDatabase;

    private WorkOrder $wo;

    private WorkOrderOutput $output;

    private Inspection $inspection;

    private User $maker;

    private User $checker;

    private User $production;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-10-06 09:00:00', config('app.timezone')));
        $this->seed([RolePermissionSeeder::class, ChartOfAccountsSeeder::class, SettingsSeeder::class]);
        config(['inspection.finish_first_wo_enabled' => false,
            'inspection.counted_capture_enabled' => false, 'inspection.checklist_capture_enabled' => false]);
        $this->maker = User::factory()->withRole('qc_inspector')->create();
        $this->checker = User::factory()->withRole('qc_inspector')->create();
        $this->production = User::factory()->withRole('production_manager')->create();
        $product = Product::factory()->create();
        app(InspectionSpecService::class)->upsertForProduct($product->id, [
            ['parameter_name' => 'Surface condition', 'parameter_type' => 'visual', 'is_critical' => false],
        ], $this->maker->id);
        $this->wo = WorkOrder::factory()->create([
            'product_id' => $product->id, 'created_by' => $this->production->id,
            'status' => 'completed', 'quantity_target' => 100, 'quantity_good' => 100, 'quantity_produced' => 100,
            'planned_start' => now(), 'planned_end' => now()->addHours(8),
        ]);
        $this->passInProcessQc($this->wo);
        $this->output = WorkOrderOutput::create([
            'work_order_id' => $this->wo->id, 'recorded_by' => $this->production->id, 'recorded_at' => now(),
            'good_count' => 100, 'reject_count' => 0, 'batch_code' => 'INLINE-100',
        ]);
        $this->inspection = Inspection::create([
            'inspection_number' => 'INLINE-QC', 'stage' => 'outgoing', 'status' => 'passed',
            'product_id' => $product->id, 'entity_type' => 'work_order', 'entity_id' => $this->wo->id,
            'work_order_output_id' => $this->output->id, 'batch_quantity' => 100, 'sample_size' => 20,
            'accepted_quantity' => 100, 'inspector_id' => $this->maker->id,
            'reviewed_by' => $this->production->id, 'reviewed_at' => now(), 'completed_at' => now(),
        ]);
        $zone = WarehouseZone::factory()->create(['zone_type' => 'finished_goods']);
        $location = WarehouseLocation::factory()->create(['zone_id' => $zone->id]);
        $this->item = Item::factory()->create(['code' => $product->part_number, 'item_type' => 'finished_good']);
        $receipt = StockMovement::create([
            'movement_type' => 'production_receipt', 'item_id' => $this->item->id, 'quantity' => '100.000',
            'unit_cost' => '5.00', 'total_cost' => '500.00', 'to_location_id' => $location->id,
            'reference_type' => 'work_order_output', 'reference_id' => $this->output->id,
            'created_by' => $this->production->id, 'lot_number' => $this->output->batch_code,
        ]);
        app(MovementGlPostingService::class)->postFor($receipt);
        $this->assertTrue($receipt->fresh()->gl_handoff_status?->isResolved());
        $this->output->forceFill(['production_receipt_movement_id' => $receipt->id, 'production_receipt_handoff_status' => 'generated'])->save();
        StockLevel::create(['item_id' => $this->item->id, 'location_id' => $location->id, 'quantity' => 100, 'reserved_quantity' => 0, 'weighted_avg_cost' => '5.00']);
    }

    private function scheduleRepair(NonConformanceReport $ncr, ?Machine $machine = null, ?Mold $mold = null): void
    {
        $crew = app(WorkOrderAssignmentService::class)->currentCrew($this->wo, $ncr->id);
        foreach ($crew->members as $member) {
            EmployeeShiftAssignment::where('employee_id', $member->employee_id)->update(['working_days' => json_encode([1, 2, 3, 4, 5, 6, 7])]);
        }
        app(WorkOrderReworkService::class)->reviewSlot($this->wo, $ncr, now(), now()->addHour(), $this->production, 'Reviewed repair interval.', $machine?->id, $mold?->id);
    }

    private function sorted(int $repairQuantity = 1): NonConformanceReport
    {
        $service = app(NcrService::class);
        $ncr = $service->create([
            'source' => 'inspection_fail', 'severity' => 'high', 'inspection_id' => $this->inspection->id,
            'affected_quantity' => $repairQuantity, 'defect_description' => 'Cracked pieces.',
        ], $this->maker);

        $sorted = $service->setDisposition($ncr, 'rework', 'Mold damage.', 'Repair segregated pieces.', $this->checker, (string) (100 - $repairQuantity), [
            'inspected_quantity' => '100', 'evidence_reference' => 'SORT-INLINE-100',
            'performed_by' => $this->maker->id, 'recovery_passed' => true,
        ]);
        if ($sorted->rework_work_order_id === $this->wo->id) {
            $this->assignProductionCrew($this->wo, $this->production, $sorted->id);
        }

        return $sorted;
    }

    public function test_open_wo_keeps_rework_on_same_order(): void
    {
        $ncr = $this->sorted();
        $this->assertSame($this->wo->id, $ncr->rework_work_order_id);
        $this->assertSame(1, (int) $ncr->confirmed_rework_quantity);
        $this->assertSame(1, WorkOrder::count());
        $this->assertSame(99, $this->inspection->fresh()->accepted_quantity);
        $this->assertSame('completed', $this->wo->fresh()->status->value);
    }

    public function test_work_order_list_exposes_rework_state_without_changing_production_status(): void
    {
        $ncr = $this->sorted(2);
        $url = '/api/v1/production/work-orders?search='.$this->wo->wo_number;
        $this->actingAs($this->production)->getJson($url)->assertOk()
            ->assertJsonPath('data.0.status', 'completed')
            ->assertJsonPath('data.0.rework_status', ['state' => 'required', 'quantity' => 2]);
        $ncr->forceFill(['rework_started_at' => now()])->save();
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.rework_status.state', 'in_progress');
        $ncr->forceFill(['rework_finished_at' => now()])->save();
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.rework_status.state', 'awaiting_qc');
        $ncr->forceFill(['status' => 'closed'])->save();
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.rework_status', null);
    }

    public function test_repair_auto_schedule_previews_then_books_equipment_and_crew_atomically(): void
    {
        $ncr = $this->sorted();
        $this->wo->update(['minimum_workers' => 2, 'maximum_workers' => 2]);
        $machine = Machine::create(['machine_code' => 'AUTO-REPAIR', 'name' => 'Repair press', 'status' => 'idle', 'tonnage' => 100, 'available_hours_per_day' => 24]);
        $mold = Mold::create(['mold_code' => 'AUTO-REPAIR', 'name' => 'Repair mold', 'product_id' => $this->wo->product_id, 'status' => 'available', 'output_rate_per_hour' => 10, 'setup_time_minutes' => 10, 'cycle_time_seconds' => 360, 'cavity_count' => 1, 'max_shots_before_maintenance' => 100000, 'lifetime_max_shots' => 1000000, 'current_shot_count' => 0]);
        $mold->compatibleMachines()->sync([$machine->id]);
        $occupied = ProductionSchedule::create(['work_order_id' => $this->wo->id,
            'machine_id' => $machine->id, 'mold_id' => $mold->id,
            'scheduled_start' => now(), 'scheduled_end' => now()->addHour(),
            'priority_order' => 0, 'status' => 'pending', 'is_confirmed' => false, 'reserves_capacity' => true]);
        $url = '/api/v1/production/work-orders/'.$this->wo->hash_id.'/rework/'.$ncr->hash_id;
        $before = $this->wo->fresh()->getRawOriginal();
        $suggestion = $this->actingAs($this->production)->getJson($url.'/suggestion?minimum_workers=1')->assertOk()->json('data');
        $this->assertSame([], $suggestion['blockers']);
        $this->assertSame($machine->hash_id, $suggestion['machine_id']);
        $this->assertSame($mold->hash_id, $suggestion['mold_id']);
        $this->assertSame(1, $suggestion['repair_quantity']);
        $this->assertTrue(Carbon::parse($suggestion['scheduled_start'])->gte($occupied->scheduled_end));
        $this->assertSame($before, $this->wo->fresh()->getRawOriginal());
        $this->assertNull(app(WorkOrderAssignmentService::class)->schedule($this->wo, $ncr->id));
        $payload = ['scheduled_start' => $suggestion['scheduled_start'], 'scheduled_end' => $suggestion['scheduled_end'],
            'machine_id' => $suggestion['machine_id'], 'mold_id' => $suggestion['mold_id'],
            'employee_ids' => array_column($suggestion['members'], 'id'), 'recorder_employee_id' => $suggestion['recorder_employee_id'],
            'expected_crew_id' => $suggestion['expected_crew_id'], 'expected_schedule_id' => $suggestion['expected_schedule_id'],
            'expected_schedule_updated_at' => $suggestion['expected_schedule_updated_at'], 'reason' => 'Approved automatic repair schedule.'];
        $this->postJson($url.'/review-slot', array_replace($payload, ['expected_crew_id' => null]))->assertUnprocessable();
        $this->assertNull(app(WorkOrderAssignmentService::class)->schedule($this->wo, $ncr->id));
        $this->postJson($url.'/review-slot', $payload)->assertOk();
        $slot = app(WorkOrderAssignmentService::class)->schedule($this->wo, $ncr->id);
        $this->assertSame($machine->id, $slot->machine_id);
        $this->assertNotNull(app(WorkOrderAssignmentService::class)->currentCrew($this->wo, $ncr->id));
        $this->assertSame(1, app(WorkOrderAssignmentService::class)->assertReadyForStart($this->wo->fresh(), $ncr->id)->members->count());
        $this->assertNull($ncr->fresh()->rework_started_at);
        $this->assertSame($before, $this->wo->fresh()->getRawOriginal());
        $this->postJson($url.'/review-slot', $payload)->assertOk();
        $this->assertSame(1, $this->wo->schedules()->where('rework_ncr_id', $ncr->id)->count());
    }

    public function test_automatic_manual_repair_suggests_crew_without_equipment_and_requires_late_approval(): void
    {
        $ncr = $this->sorted();
        $this->wo->update(['planned_end' => now()->addMinutes(30)]);
        $url = '/api/v1/production/work-orders/'.$this->wo->hash_id.'/rework/'.$ncr->hash_id;
        $this->actingAs($this->maker)->getJson($url.'/suggestion?minimum_workers=1&manual_repair=1')->assertForbidden();
        $suggestion = $this->actingAs($this->production)->getJson($url.'/suggestion?minimum_workers=1&manual_repair=1')->assertOk()->json('data');
        $this->assertSame([], $suggestion['blockers']);
        $this->assertNull($suggestion['machine_id']);
        $this->assertNull($suggestion['mold_id']);
        $this->assertTrue($suggestion['requires_late_production_approval']);
        $payload = ['scheduled_start' => $suggestion['scheduled_start'], 'scheduled_end' => $suggestion['scheduled_end'],
            'employee_ids' => array_column($suggestion['members'], 'id'), 'recorder_employee_id' => $suggestion['recorder_employee_id'],
            'expected_crew_id' => $suggestion['expected_crew_id'], 'expected_schedule_id' => $suggestion['expected_schedule_id'],
            'expected_schedule_updated_at' => $suggestion['expected_schedule_updated_at'], 'reason' => 'Approved manual repair.'];
        $this->postJson($url.'/review-slot', $payload)->assertUnprocessable();
        $this->assertNull(app(WorkOrderAssignmentService::class)->schedule($this->wo, $ncr->id));
        $this->postJson($url.'/review-slot', $payload + ['late_production_authorized' => true,
            'late_production_reason' => 'Repair will extend the original deadline.'])->assertOk();
        $this->assertNull(app(WorkOrderAssignmentService::class)->schedule($this->wo, $ncr->id)->machine_id);
        $this->assertNull($ncr->fresh()->rework_started_at);
    }

    public function test_manual_repair_without_equipment_requires_late_approval_and_rechecks_at_start(): void
    {
        $ncr = $this->sorted();
        $this->wo->update(['planned_end' => now()->addMinutes(30)]);
        $slot = app(WorkOrderAssignmentService::class)->schedule($this->wo, $ncr->id);
        $payload = ['scheduled_start' => now()->toIso8601String(), 'scheduled_end' => now()->addHour()->toIso8601String(),
            'expected_schedule_id' => $slot?->hash_id, 'expected_schedule_updated_at' => $slot?->updated_at?->toISOString(),
            'reason' => 'Repair after the original deadline.'];
        $url = '/api/v1/production/work-orders/'.$this->wo->hash_id.'/rework/'.$ncr->hash_id;
        $before = $this->wo->schedules()->count();
        $this->actingAs($this->production, 'sanctum')->postJson($url.'/review-slot', $payload)->assertUnprocessable();
        $this->assertSame($before, $this->wo->schedules()->count());
        $this->postJson($url.'/review-slot', $payload + ['late_production_authorized' => true,
            'late_production_reason' => 'Outgoing QC and delivery must wait for the repair.'])->assertOk();
        app(ProductionResourceService::class)->assertExecutionSlot($this->wo->fresh(), ncrId: $ncr->id);
        $this->wo->update(['planned_end' => now()->addMinutes(20)]);
        $this->postJson($url.'/start')->assertUnprocessable();
        $this->assertNull($ncr->fresh()->rework_started_at);
    }

    public function test_repair_start_distinguishes_future_and_expired_slots_and_accepts_the_start_boundary(): void
    {
        $ncr = $this->sorted();
        $this->scheduleRepair($ncr);
        $slot = app(WorkOrderAssignmentService::class)->schedule($this->wo, $ncr->id);
        $slot->update(['scheduled_start' => now()->addHour(), 'scheduled_end' => now()->addHours(3)]);
        $url = '/api/v1/production/work-orders/'.$this->wo->hash_id.'/rework/'.$ncr->hash_id.'/start';
        $execution = ['expected_crew_id' => app(WorkOrderAssignmentService::class)->currentCrew($this->wo, $ncr->id)->hash_id];
        $this->actingAs($this->production, 'sanctum')->getJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/crew?rework_ncr_id='.$ncr->hash_id)
            ->assertOk()->assertJsonPath('data.server_time', now()->toIso8601String());
        $this->postJson($url, $execution)->assertUnprocessable()->assertJsonPath('message', 'Rework starts at Oct 6, 2026 · 10:00 AM (Asia/Manila).');
        $this->assertNull($ncr->fresh()->rework_started_at);
        $this->travel(1)->hours();
        $this->assertSame($slot->id, app(ProductionResourceService::class)->assertExecutionSlot($this->wo->fresh(), ncrId: $ncr->id)->id);
        $this->travel(2)->hours();
        try {
            app(ProductionResourceService::class)->assertExecutionSlot($this->wo->fresh(), ncrId: $ncr->id);
            $this->fail('An expired repair slot must block start.');
        } catch (BusinessRuleException $error) {
            $this->assertSame('Repair slot expired. Auto schedule a new slot.', $error->getMessage());
        }
        $this->assertNull($ncr->fresh()->rework_started_at);
    }

    public function test_http_repair_crew_assignment_returns_the_committed_repair_context(): void
    {
        $ncr = $this->sorted();
        $crew = app(WorkOrderAssignmentService::class)->currentCrew($this->wo, $ncr->id);
        $this->scheduleRepair($ncr);
        $worker = $crew->members->first()->employee;
        $this->actingAs($this->production, 'sanctum')->postJson('/api/v1/production/work-orders/'.$this->wo->hash_id.'/crew', [
            'rework_ncr_id' => $ncr->hash_id,
            'employee_ids' => [$worker->hash_id],
            'recorder_employee_id' => $worker->hash_id,
            'minimum_workers' => 1, 'maximum_workers' => null,
            'expected_crew_id' => $crew->hash_id, 'reason' => 'Repair crew handover.',
        ])->assertOk()->assertJsonPath('data.current.rework_ncr_id', $ncr->hash_id);
        $this->assertSame(2, $this->wo->crews()->where('rework_ncr_id', $ncr->id)->count());
    }

    public function test_closed_wo_keeps_separate_repair_path(): void
    {
        $this->wo->forceFill(['status' => 'closed'])->save();
        $ncr = $this->sorted();
        $this->assertNotSame($this->wo->id, $ncr->rework_work_order_id);
        $this->assertSame(1, (int) $ncr->reworkWorkOrder->quantity_target);
        $this->assertSame('closed', $this->wo->fresh()->status->value);
    }

    public function test_inline_repair_conserves_stock_and_original_production_totals(): void
    {
        $ncr = $this->sorted();
        $service = app(WorkOrderReworkService::class);
        $this->scheduleRepair($ncr);
        $service->start($this->wo, $ncr, $this->production);
        $output = $service->record($this->wo, $ncr, $this->outputForCrew($this->wo, ['good_count' => 1, 'reject_count' => 0], $this->production, $ncr->id), $this->production, 'inline-repair-one');
        $this->assertSame($this->wo->id, $output->work_order_id);
        $this->assertSame($ncr->id, $output->rework_ncr_id);
        $this->assertSame($this->output->id, $output->rework_source_output_id);
        $this->assertEquals(100, StockLevel::where('item_id', $this->item->id)->sum('quantity'));
        $this->assertSame(100, (int) $this->wo->fresh()->quantity_good);
        $this->assertSame(100, (int) $this->wo->fresh()->quantity_produced);
        $this->assertSame(0, (int) $this->wo->fresh()->quantity_rejected);
        $consumption = StockMovement::where('reference_type', 'work_order_output')->where('reference_id', $output->id)->where('movement_type', 'adjustment_out')->firstOrFail();
        $this->assertTrue($consumption->gl_handoff_status?->isResolved());
        $mapping = new \ReflectionMethod(MovementGlPostingService::class, 'mapping');
        $this->assertSame('accounting.accounts.material_consumption_code', $mapping->invoke(app(MovementGlPostingService::class), $consumption, $this->item)[3]);
        $this->assertDatabaseHas('inspections', ['work_order_output_id' => $output->id, 'stage' => 'outgoing', 'status' => 'draft']);
        $this->assertSame($output->id, $service->record($this->wo, $ncr, $this->outputForCrew($this->wo, ['good_count' => 1, 'reject_count' => 0], $this->production, $ncr->id), $this->production, 'inline-repair-one')->id);
    }

    public function test_repair_output_requires_explicit_production_start(): void
    {
        $ncr = $this->sorted();
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Start');
        app(WorkOrderReworkService::class)->record($this->wo, $ncr, $this->outputForCrew($this->wo, ['good_count' => 1, 'reject_count' => 0], $this->production, $ncr->id), $this->production);
    }

    public function test_repair_output_cannot_exceed_confirmed_quantity(): void
    {
        $ncr = $this->sorted();
        $service = app(WorkOrderReworkService::class);
        $this->scheduleRepair($ncr);
        $service->start($this->wo, $ncr, $this->production);
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('confirmed');
        $service->record($this->wo, $ncr, $this->outputForCrew($this->wo, ['good_count' => 2, 'reject_count' => 0], $this->production, $ncr->id), $this->production);
    }

    public function test_ncr_requires_finished_rework_and_checked_repair_qc(): void
    {
        $ncr = $this->sorted();
        $service = app(NcrService::class);
        foreach (['corrective', 'preventive'] as $type) {
            $service->addAction($ncr, ['action_type' => $type, 'description' => 'Documented action.'], $this->maker);
        }
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('repair');
        $service->close($ncr, $this->checker);
    }

    public function test_full_piece_repair_closes_from_repair_and_qc_evidence_without_manual_actions(): void
    {
        $this->inspection->forceFill([
            'counted_capture' => ['version' => 3, 'workflow' => 'finish_first_v1', 'evidence_method' => 'confirmed_checks'],
            'sample_defect_count' => 1, 'inspected_quantity' => 100,
        ])->save();
        $ncr = app(NcrService::class)->create([
            'source' => 'inspection_fail', 'severity' => 'high', 'inspection_id' => $this->inspection->id,
            'affected_quantity' => 100, 'defect_description' => 'One cracked piece.',
        ], $this->maker);
        $ncr = app(NcrService::class)->setDisposition($ncr, 'rework', null, null, $this->checker, '99', [
            'inspected_quantity' => '100', 'evidence_reference' => 'SEGREGATION-100',
            'performed_by' => $this->maker->id, 'recovery_passed' => true, 'defects_segregated' => true,
        ]);
        $this->assertCount(0, $ncr->actions);
        try {
            app(NcrService::class)->close($ncr, $this->checker);
            $this->fail('Unfinished repair must block closure.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('Finish the inline repair', $error->getMessage());
        }
        $this->assignProductionCrew($this->wo, $this->production, $ncr->id);
        $this->scheduleRepair($ncr);
        $repair = app(WorkOrderReworkService::class);
        $repair->start($this->wo, $ncr, $this->production);
        $output = $repair->record($this->wo, $ncr, $this->outputForCrew($this->wo, ['good_count' => 1, 'reject_count' => 0], $this->production, $ncr->id), $this->production);
        $repair->finish($this->wo, $ncr, $this->production);
        try {
            app(NcrService::class)->close($ncr, $this->checker);
            $this->fail('Unreviewed repair QC must block closure.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('independently checked', $error->getMessage());
        }
        $qc = Inspection::where('work_order_output_id', $output->id)->firstOrFail();
        $qc->forceFill(['status' => 'passed', 'accepted_quantity' => 1, 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()])->save();
        $closed = app(NcrService::class)->close($ncr, $this->checker);
        $this->assertSame('closed', $closed->status->value);
        $this->assertCount(0, $closed->actions);
        $this->assertSame('closed', $this->wo->fresh()->status->value);
        $this->assertSame('not_applicable', $closed->effectiveness_status->value);
    }

    public function test_full_piece_scrap_uses_disposition_evidence_without_manual_actions(): void
    {
        $this->inspection->forceFill([
            'counted_capture' => ['version' => 3, 'workflow' => 'finish_first_v1', 'evidence_method' => 'confirmed_checks'],
            'sample_defect_count' => 2, 'inspected_quantity' => 100,
        ])->save();
        $ncr = app(NcrService::class)->create([
            'source' => 'inspection_fail', 'severity' => 'high', 'inspection_id' => $this->inspection->id,
            'affected_quantity' => 100, 'defect_description' => 'Two cracked pieces.',
        ], $this->maker);
        $ncr = app(NcrService::class)->setDisposition($ncr, 'scrap', null, null, $this->checker, '98', [
            'inspected_quantity' => '100', 'evidence_reference' => 'SCRAP-SEGREGATION-100',
            'performed_by' => $this->maker->id, 'recovery_passed' => true, 'defects_segregated' => true,
        ]);
        $closed = app(NcrService::class)->close($ncr, $this->checker);
        $this->assertSame('closed', $closed->status->value);
        $this->assertCount(0, $closed->actions);
        $this->assertSame(2, (int) $closed->replacementWorkOrder->quantity_target);
        $this->assertSame(2, (int) StockMovement::where('movement_type', 'scrap')->sum('quantity'));
    }

    public function test_resolved_wo_closes_automatically_once_with_notification(): void
    {
        $service = app(WorkOrderService::class);
        $this->assertTrue($service->autoCloseIfReady($this->wo, $this->production));
        $this->assertSame('closed', $this->wo->fresh()->status->value);
        $this->assertFalse($service->autoCloseIfReady($this->wo, $this->production));
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->production->id, 'type' => 'production.rework.closed']);
        $data = json_decode((string) DB::table('notifications')
            ->where('notifiable_id', $this->production->id)
            ->where('type', 'production.rework.closed')
            ->value('data'), true);
        $this->assertSame('WO '.$this->wo->wo_number.' — Rework completed', $data['title']);
    }

    public function test_auto_close_refuses_unchecked_qc_or_missing_stock_receipt(): void
    {
        $service = app(WorkOrderService::class);
        $this->inspection->forceFill(['reviewed_by' => null, 'reviewed_at' => null])->save();
        $this->assertFalse($service->autoCloseIfReady($this->wo, $this->production));
        $this->inspection->forceFill(['reviewed_by' => $this->production->id, 'reviewed_at' => now()])->save();
        $this->output->forceFill(['production_receipt_movement_id' => null, 'production_receipt_handoff_status' => 'manual_required'])->save();
        $this->assertFalse($service->autoCloseIfReady($this->wo, $this->production));
        $this->assertSame('completed', $this->wo->fresh()->status->value);
    }

    public function test_auto_close_waits_for_finished_repair_checked_qc_and_ncr_resolution(): void
    {
        $ncr = $this->sorted();
        $woService = app(WorkOrderService::class);
        $this->assertFalse($woService->autoCloseIfReady($this->wo, $this->production));
        $rework = app(WorkOrderReworkService::class);
        $this->scheduleRepair($ncr);
        $rework->start($this->wo, $ncr, $this->production);
        $output = $rework->record($this->wo, $ncr, $this->outputForCrew($this->wo, ['good_count' => 1, 'reject_count' => 0], $this->production, $ncr->id), $this->production);
        $rework->finish($this->wo, $ncr, $this->production);
        $this->assertFalse($woService->autoCloseIfReady($this->wo, $this->production));
        $qc = Inspection::where('work_order_output_id', $output->id)->firstOrFail();
        $qc->forceFill(['status' => 'passed', 'accepted_quantity' => 1, 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()])->save();
        $this->assertFalse($woService->autoCloseIfReady($this->wo, $this->production));
        foreach (['corrective', 'preventive'] as $type) {
            app(NcrService::class)->addAction($ncr, ['action_type' => $type, 'description' => 'Documented action.'], $this->maker);
        }
        app(NcrService::class)->close($ncr, $this->checker);
        $this->assertSame('closed', $this->wo->fresh()->status->value);
        $this->assertFalse($woService->autoCloseIfReady($this->wo, $this->production));
    }

    private function closeActions(NonConformanceReport $ncr): void
    {
        foreach (['corrective', 'preventive'] as $type) {
            app(NcrService::class)->addAction($ncr, ['action_type' => $type, 'description' => 'Documented action.'], $this->maker);
        }
        app(NcrService::class)->close($ncr, $this->checker);
    }

    public function test_inline_repair_is_a_commitment_without_extra_mrp_demand(): void
    {
        $line = SalesOrderItem::factory()->create(['product_id' => $this->wo->product_id, 'quantity' => 100]);
        $this->wo->forceFill(['sales_order_id' => $line->sales_order_id, 'sales_order_item_id' => $line->id])->save();
        $ncr = $this->sorted();
        $method = new \ReflectionMethod(MrpEngineService::class, 'remainingToProduce');
        $engine = app(MrpEngineService::class);
        $this->assertSame(0.0, $method->invoke($engine, $line));
        $rework = app(WorkOrderReworkService::class);
        $this->scheduleRepair($ncr);
        $rework->start($this->wo, $ncr, $this->production);
        $rework->record($this->wo, $ncr, $this->outputForCrew($this->wo, ['good_count' => 1, 'reject_count' => 0], $this->production, $ncr->id), $this->production);
        $this->assertSame(0.0, $method->invoke($engine, $line));
    }

    public function test_all_source_pieces_can_be_repaired_and_wo_closed(): void
    {
        $ncr = app(NcrService::class)->create(['source' => 'inspection_fail', 'severity' => 'high', 'inspection_id' => $this->inspection->id,
            'affected_quantity' => 100, 'defect_description' => 'Entire lot requires polishing.'], $this->maker);
        $ncr = app(NcrService::class)->setDisposition($ncr, 'rework', 'Surface issue.', 'Polish lot.', $this->checker, '0', [
            'inspected_quantity' => '100', 'evidence_reference' => 'SORT-ALL', 'performed_by' => $this->maker->id, 'recovery_passed' => true]);
        $this->assignProductionCrew($this->wo, $this->production, $ncr->id);
        $repair = app(WorkOrderReworkService::class);
        $this->scheduleRepair($ncr);
        $repair->start($this->wo, $ncr, $this->production);
        $output = $repair->record($this->wo, $ncr, $this->outputForCrew($this->wo, ['good_count' => 100, 'reject_count' => 0], $this->production, $ncr->id), $this->production);
        $repair->finish($this->wo, $ncr, $this->production);
        $qc = Inspection::where('work_order_output_id', $output->id)->firstOrFail();
        $qc->forceFill(['status' => 'passed', 'accepted_quantity' => 100, 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()])->save();
        $this->closeActions($ncr);
        $this->assertSame('closed', $this->wo->fresh()->status->value);
        $this->assertFalse(app(WorkOrderService::class)->autoCloseIfReady($this->wo, $this->production));
        $this->assertEquals(100, StockLevel::where('item_id', $this->item->id)->sum('quantity'));
    }

    public function test_rejected_inline_piece_is_scrapped_and_replacement_handed_off(): void
    {
        $ncr = $this->sorted();
        $defect = DefectType::create(['code' => 'CRACK', 'name' => 'Crack', 'is_active' => true]);
        $repair = app(WorkOrderReworkService::class);
        $this->scheduleRepair($ncr);
        $repair->start($this->wo, $ncr, $this->production);
        $repair->record($this->wo, $ncr, $this->outputForCrew($this->wo, ['good_count' => 0, 'reject_count' => 1, 'defects' => [['defect_type_id' => $defect->id, 'count' => 1]]], $this->production, $ncr->id), $this->production);
        $repair->finish($this->wo, $ncr, $this->production);
        $this->closeActions($ncr);
        $this->assertEquals(99, StockLevel::where('item_id', $this->item->id)->sum('quantity'));
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'scrap', 'quantity' => 1, 'item_id' => $this->item->id]);
        $replacement = $ncr->fresh()->replacementWorkOrder;
        $this->assertNotNull($replacement);
        $this->assertSame(1, (int) $replacement->quantity_target);
    }

    public function test_finish_first_inline_repair_defers_qc_and_excludes_scrapped_pieces(): void
    {
        $ncr = $this->sorted(5);
        DB::table('work_orders')->where('id', $this->wo->id)->update(['qc_workflow' => 'finish_first_v1']);
        $this->wo = $this->wo->fresh();
        app(InspectionSpecService::class)->upsertForProduct($this->wo->product_id, [[
            'parameter_name' => 'Surface condition', 'parameter_type' => 'visual',
            'acceptance_criterion' => 'No visible damage.', 'is_critical' => false,
        ]], $this->maker->id);
        $repair = app(WorkOrderReworkService::class);
        $this->scheduleRepair($ncr);
        $repair->start($this->wo, $ncr, $this->production);
        $defect = DefectType::create(['code' => 'RPSCRAP', 'name' => 'Repair scrap', 'is_active' => true]);
        $output = $repair->record($this->wo, $ncr, $this->outputForCrew($this->wo, [
            'good_count' => 3, 'reject_count' => 2,
            'defects' => [['defect_type_id' => $defect->id, 'count' => 2]],
        ], $this->production, $ncr->id), $this->production, 'finish-first-inline-repair');

        $this->assertSame(5, $output->physical_count);
        $this->assertSame(100, (int) $this->wo->fresh()->quantity_produced);
        $this->assertDatabaseMissing('inspections', ['work_order_output_id' => $output->id, 'stage' => 'outgoing']);
        $repair->finish($this->wo, $ncr, $this->production);

        $repairInspection = Inspection::query()->where('work_order_output_id', $output->id)->firstOrFail();
        $this->assertTrue($repairInspection->usesFullPieceCapture());
        $this->assertSame(3, $repairInspection->batch_quantity);
        $this->assertSame('3.000', $repairInspection->source_quantity);
        $this->assertSame(3, $repairInspection->counted_capture['required_quantity']);
        $this->assertSame(100, (int) $this->wo->fresh()->quantity_produced);
        $this->assertEquals(98, StockLevel::where('item_id', $this->item->id)->sum('quantity'));
        $this->assertDatabaseHas('stock_movements', ['reference_type' => 'work_order_output', 'reference_id' => $output->id, 'movement_type' => 'scrap', 'quantity' => 2]);
    }

    public function test_nested_repair_qc_resolves_parent_without_inflating_stock(): void
    {
        $parent = $this->sorted();
        $repair = app(WorkOrderReworkService::class);
        $this->scheduleRepair($parent);
        $repair->start($this->wo, $parent, $this->production);
        $first = $repair->record($this->wo, $parent, $this->outputForCrew($this->wo, ['good_count' => 1, 'reject_count' => 0], $this->production, $parent->id), $this->production);
        $repair->finish($this->wo, $parent, $this->production);
        $qc = Inspection::where('work_order_output_id', $first->id)->firstOrFail();
        $qc->forceFill(['status' => 'passed', 'accepted_quantity' => 1, 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()])->save();
        $child = app(NcrService::class)->create(['source' => 'inspection_fail', 'severity' => 'high', 'inspection_id' => $qc->id,
            'affected_quantity' => 1, 'defect_description' => 'Repair still cracked.'], $this->maker);
        $child = app(NcrService::class)->setDisposition($child, 'rework', 'Repair failed.', 'Repair again.', $this->checker, '0', [
            'inspected_quantity' => '1', 'evidence_reference' => 'SORT-NESTED', 'performed_by' => $this->maker->id, 'recovery_passed' => true]);
        $this->assignProductionCrew($this->wo, $this->production, $child->id);
        $this->scheduleRepair($child);
        $repair->start($this->wo, $child, $this->production);
        $second = $repair->record($this->wo, $child, $this->outputForCrew($this->wo, ['good_count' => 1, 'reject_count' => 0], $this->production, $child->id), $this->production);
        $repair->finish($this->wo, $child, $this->production);
        $qc2 = Inspection::where('work_order_output_id', $second->id)->firstOrFail();
        $qc2->forceFill(['status' => 'passed', 'accepted_quantity' => 1, 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()])->save();
        $this->closeActions($child);
        $this->closeActions($parent);
        $this->assertSame('closed', $this->wo->fresh()->status->value);
        $this->assertFalse(app(WorkOrderService::class)->autoCloseIfReady($this->wo, $this->production));
        $this->assertEquals(100, StockLevel::where('item_id', $this->item->id)->sum('quantity'));
    }

    public function test_machine_repair_uses_confirmed_quantity_and_tracks_mold_shots(): void
    {
        $ncr = $this->sorted();
        $machine = Machine::factory()->create(['status' => 'idle']);
        $mold = Mold::create(['mold_code' => 'INLINE-MOLD', 'name' => 'Repair mold', 'product_id' => $this->wo->product_id,
            'cavity_count' => 1, 'cycle_time_seconds' => 30, 'output_rate_per_hour' => 100, 'setup_time_minutes' => 0,
            'current_shot_count' => 8, 'max_shots_before_maintenance' => 10, 'lifetime_max_shots' => 1000, 'status' => 'available']);
        $mold->compatibleMachines()->attach($machine->id);
        $repair = app(WorkOrderReworkService::class);
        $this->scheduleRepair($ncr, $machine, $mold);
        $repair->start($this->wo, $ncr, $this->production, $machine->id, $mold->id);
        $this->assertSame('running', $machine->fresh()->status->value);
        $otherMachine = Machine::factory()->create(['status' => 'idle']);
        $other = WorkOrder::factory()->create(['product_id' => $this->wo->product_id, 'machine_id' => $otherMachine->id, 'mold_id' => $mold->id,
            'status' => 'confirmed', 'quantity_target' => 1, 'work_order_class' => 'non_stock', 'exception_reason' => 'Occupancy test', 'exception_authorized_by' => $this->production->id]);
        $this->assignProductionCrew($other, User::factory()->create());
        $mold->compatibleMachines()->attach($otherMachine->id);
        try {
            app(WorkOrderService::class)->start($other, $this->production->id);
            $this->fail('A mold cannot serve production while inline repair owns it.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('occupied', $error->getMessage());
        }
        $repair->record($this->wo, $ncr, $this->outputForCrew($this->wo, ['good_count' => 1, 'reject_count' => 0], $this->production, $ncr->id), $this->production);
        $this->assertSame(9, $mold->fresh()->current_shot_count);
        $repair->finish($this->wo, $ncr, $this->production);
        $this->assertSame('idle', $machine->fresh()->status->value);
        $this->assertSame('available', $mold->fresh()->status->value);
    }

    public function test_delayed_duplicate_quality_event_rechecks_latest_state(): void
    {
        $message = app(OutboxService::class)->record(new WorkOrderQualityResolved($this->wo, $this->production));
        $this->travel(2)->seconds();
        $ncr = $this->sorted();
        $event = app(OutboxEventCodec::class)->decode($message->event_type, $message->payload);
        app(AutoCloseResolvedWorkOrder::class)->handle($event);
        $this->assertSame('completed', $this->wo->fresh()->status->value);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->production->id, 'type' => 'production.rework.required']);
        $this->assertSame($this->wo->id, $ncr->rework_work_order_id);
    }
}
