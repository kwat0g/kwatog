<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\MovementGlPostingService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\MRP\Events\MrpPlanGenerated;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\BomItem;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Services\MrpEngineService;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\ProductRouting;
use App\Modules\Production\Models\RoutingOperation;
use App\Modules\Production\Models\WoOperation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderCrew;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Production\Services\WoOperationService;
use App\Modules\Production\Services\WorkOrderOutputService;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Quality\Events\OutgoingQuantityReleased;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Resources\NcrResource;
use App\Modules\Quality\Services\InspectionService;
use App\Modules\Quality\Services\NcrService;
use App\Modules\Quality\Services\QualityStockHoldService;
use App\Modules\SupplyChain\Services\DeliveryService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\HasAssignedProductionCrew;
use Tests\Support\HasPassedInProcessQc;
use Tests\TestCase;

class WorkOrderQcConnectionTest extends TestCase
{
    use HasAssignedProductionCrew;
    use HasPassedInProcessQc;
    use RefreshDatabase;

    private User $maker;

    private User $checker;

    private User $sorter;

    private User $approver;

    private User $repairOperator;

    private Product $product;

    private WorkOrder $wo;

    private WorkOrderOutput $output;

    private Inspection $inspection;

    private SalesOrderItem $line;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed([ChartOfAccountsSeeder::class, SettingsSeeder::class]);
        $this->maker = User::factory()->create();
        $this->checker = User::factory()->create();
        $this->sorter = User::factory()->create();
        $this->approver = User::factory()->create();
        $this->repairOperator = User::factory()->create();
        $this->product = Product::factory()->create();
        $so = SalesOrder::factory()->create();
        $this->line = SalesOrderItem::factory()->create(['sales_order_id' => $so->id, 'product_id' => $this->product->id, 'quantity' => 100, 'quantity_delivered' => 0]);
        $this->wo = WorkOrder::factory()->create(['product_id' => $this->product->id, 'sales_order_id' => $so->id, 'sales_order_item_id' => $this->line->id, 'status' => 'closed', 'quantity_target' => 100, 'quantity_good' => 100, 'quantity_produced' => 100, 'created_by' => $this->maker->id]);
        $this->passInProcessQc($this->wo);
        $this->output = WorkOrderOutput::create(['work_order_id' => $this->wo->id, 'recorded_by' => $this->maker->id, 'recorded_at' => now(), 'good_count' => 100, 'reject_count' => 0, 'batch_code' => 'QC-CONNECTION-100']);
        $this->inspection = Inspection::create(['inspection_number' => 'QC-CONNECTION-100', 'stage' => 'outgoing', 'status' => 'passed', 'product_id' => $this->product->id, 'entity_type' => 'work_order', 'entity_id' => $this->wo->id, 'work_order_output_id' => $this->output->id, 'batch_quantity' => 100, 'sample_size' => 20, 'accepted_quantity' => 100, 'inspector_id' => $this->maker->id, 'reviewed_by' => $this->checker->id, 'reviewed_at' => now(), 'completed_at' => now()]);
        $spec = InspectionSpec::create(['product_id' => $this->product->id, 'version' => 1, 'is_active' => true, 'created_by' => $this->maker->id]);
        InspectionSpecItem::create(['inspection_spec_id' => $spec->id, 'parameter_name' => 'Surface condition', 'parameter_type' => 'visual', 'is_critical' => false]);
    }

    public function test_produced_work_order_cannot_be_cancelled(): void
    {
        $this->wo->forceFill(['status' => 'paused'])->save();
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('produced');
        app(WorkOrderService::class)->cancel($this->wo, 'Stop produced work');
    }

    public function test_closed_work_order_cannot_create_new_outgoing_inspection(): void
    {
        config(['inspection.checklist_capture_enabled' => true]);
        $this->inspection->forceFill(['status' => 'cancelled'])->save();
        $spec = InspectionSpec::where('product_id', $this->product->id)->firstOrFail();
        $spec->ensureCurrentRevision();
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('closed');
        app(InspectionService::class)->createForOutput($this->output, $this->wo, $this->maker);
    }

    private function report(): NonConformanceReport
    {
        return app(NcrService::class)->create(['source' => 'inspection_fail', 'severity' => 'high', 'inspection_id' => $this->inspection->id, 'product_id' => $this->product->id, 'affected_quantity' => 1, 'defect_description' => 'Cracked piece found after approval.'], $this->approver);
    }

    private function sort(NonConformanceReport $ncr, string $disposition = 'rework'): NonConformanceReport
    {
        $ncr = app(NcrService::class)->setDisposition($ncr, $disposition, 'Mold flash', null, $this->approver, '99', ['inspected_quantity' => '100', 'evidence_reference' => 'SORT-100', 'performed_by' => $this->sorter->id, 'recovery_passed' => true]);
        if ($ncr->rework_work_order_id && $ncr->rework_work_order_id !== $this->wo->id) {
            $this->passInProcessQc(WorkOrder::findOrFail($ncr->rework_work_order_id));
        }

        return $ncr;
    }

    /** Explicit production state for output-service tests; the browser E2E covers lifecycle gates. */
    private function repairOutputStartingState(WorkOrder $repair): WorkOrderCrew
    {
        $machine = Machine::factory()->create();
        $mold = Mold::create([
            'mold_code' => 'QC-REWORK-'.$repair->id,
            'name' => 'QC repair mold',
            'product_id' => $repair->product_id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 30,
            'output_rate_per_hour' => 100,
            'setup_time_minutes' => 0,
            'current_shot_count' => 0,
            'max_shots_before_maintenance' => 1000,
            'lifetime_max_shots' => 100000,
            'status' => 'available',
        ]);
        $mold->compatibleMachines()->sync([$machine->id]);
        $repair->forceFill([
            'status' => 'in_progress',
            'machine_id' => $machine->id,
            'mold_id' => $mold->id,
        ])->save();
        $machine->forceFill(['status' => 'running', 'current_work_order_id' => $repair->id])->save();
        $mold->forceFill(['status' => 'in_use', 'current_work_order_id' => $repair->id])->save();
        ProductionSchedule::create([
            'work_order_id' => $repair->id,
            'machine_id' => $machine->id,
            'mold_id' => $mold->id,
            'scheduled_start' => now()->subMinute(),
            'scheduled_end' => now()->addHours(2),
            'priority_order' => 0,
            'status' => 'confirmed',
            'is_confirmed' => true,
            'confirmed_by' => $this->maker->id,
            'confirmed_at' => now(),
            'reserves_capacity' => true,
        ]);
        $this->passInProcessQc($repair);

        return $this->assignProductionCrew($repair, $this->repairOperator);
    }

    public function test_late_defect_holds_a_passed_batch_without_reopening_closed_wo(): void
    {
        $this->wo->forceFill(['status' => 'closed'])->save();
        $this->report();
        $this->assertFalse($this->inspection->fresh()->canReleaseOutgoing());
        $this->assertSame('closed', $this->wo->fresh()->status->value);
        $this->assertSame('passed', $this->inspection->fresh()->status->value);
    }

    public function test_sorting_creates_one_linked_repair_for_one_piece_and_releases_99(): void
    {
        $ncr = $this->sort($this->report());
        $this->assertNotNull($ncr->rework_work_order_id);
        $repair = WorkOrder::findOrFail($ncr->rework_work_order_id);
        $this->assertSame(1, (int) $repair->quantity_target);
        $this->assertSame($this->output->id, $repair->rework_source_output_id);
        $this->assertSame($this->line->id, $repair->sales_order_item_id);
        $this->assertCount(0, $repair->materials);
        $this->assertSame(99, $this->inspection->fresh()->accepted_quantity);
        $this->assertTrue($this->inspection->fresh()->canReleaseOutgoing());
        $this->assertDatabaseHas('event_outbox', ['event_type' => OutgoingQuantityReleased::class]);
    }

    public function test_ncr_cannot_close_while_linked_repair_is_unfinished(): void
    {
        $ncr = $this->sort($this->report());
        $service = app(NcrService::class);
        foreach (['corrective', 'preventive'] as $type) {
            $service->addAction($ncr, ['action_type' => $type, 'description' => 'Documented action.'], $this->maker);
        }
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('repair');
        $service->close($ncr, $this->approver);
    }

    public function test_failed_sample_remains_production_commitment_until_material_decision(): void
    {
        $this->inspection->forceFill(['status' => 'failed', 'accepted_quantity' => 0])->save();
        $this->report();
        $remaining = new \ReflectionMethod(MrpEngineService::class, 'remainingToProduce');
        $this->assertSame(0.0, $remaining->invoke(app(MrpEngineService::class), $this->line));
    }

    public function test_ncr_detail_exposes_the_full_outgoing_recovery_context(): void
    {
        $this->inspection->forceFill(['status' => 'failed', 'accepted_quantity' => 0])->save();
        $payload = (new NcrResource(app(NcrService::class)->show($this->report())))->resolve();
        $this->assertTrue($payload['is_outgoing_recovery']);
        $this->assertSame(100, $payload['sorting_required_quantity']);
    }

    public function test_closed_ncr_does_not_prevent_reporting_another_defect_on_same_batch(): void
    {
        $first = $this->report();
        $first->forceFill(['status' => 'closed'])->save();
        $second = $this->report();
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($second->id, $this->inspection->fresh()->ncr->id);
        $this->assertFalse($this->inspection->fresh()->canReleaseOutgoing());
    }

    public function test_wo_cannot_close_when_second_output_batch_has_no_qc(): void
    {
        $this->wo->forceFill(['status' => 'completed'])->save();
        WorkOrderOutput::create(['work_order_id' => $this->wo->id, 'recorded_by' => $this->maker->id, 'recorded_at' => now(), 'good_count' => 10, 'reject_count' => 0, 'batch_code' => 'QC-CONNECTION-10']);
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('QC coverage');
        app(WorkOrderService::class)->close($this->wo);
    }

    public function test_required_operation_persists_qc_and_waits_for_its_pass(): void
    {
        $this->wo->forceFill(['status' => 'in_progress'])->save();
        $routing = ProductRouting::create(['product_id' => $this->product->id, 'version' => 1, 'is_active' => true]);
        $routingOperation = RoutingOperation::create(['routing_id' => $routing->id, 'sequence' => 10, 'operation_name' => 'Visual gate', 'work_center' => 'QC', 'cycle_time_minutes' => '1.00', 'qc_required' => true]);
        $op = WoOperation::create(['work_order_id' => $this->wo->id, 'routing_operation_id' => $routingOperation->id, 'sequence' => 10, 'operation_name' => 'Visual gate', 'status' => 'in_progress', 'qty_planned' => 100, 'qty_completed' => 100]);
        try {
            app(WoOperationService::class)->completeOperation($op);
            $this->fail('A pending inspection must prevent completing this operation.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('QC', $e->getMessage());
        }
        $inspection = Inspection::where('wo_operation_id', $op->id)->firstOrFail();
        $this->assertSame('in_progress', $op->fresh()->status->value);
        try {
            app(WoOperationService::class)->recordOutput($op->fresh(), 1, 0, null);
            $this->fail('Output cannot change after operation inspection starts.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString($inspection->inspection_number, $e->getMessage());
        }
        $this->assertSame(100, (int) $op->fresh()->qty_completed);
        $inspection->forceFill([
            'status' => 'passed',
            'reviewed_by' => $this->checker->id,
            'reviewed_at' => now(),
        ])->save();
        app(WoOperationService::class)->completeOperation($op->fresh());
        $this->assertSame('completed', $op->fresh()->status->value);
    }

    public function test_held_finished_goods_cannot_be_reserved_or_issued_as_material(): void
    {
        $zone = WarehouseZone::factory()->create(['zone_type' => 'finished_goods']);
        $location = WarehouseLocation::factory()->create(['zone_id' => $zone->id]);
        $item = Item::factory()->create(['code' => $this->product->part_number, 'item_type' => 'finished_good']);
        $receipt = StockMovement::create(['movement_type' => 'production_receipt', 'item_id' => $item->id, 'quantity' => '100.000', 'unit_cost' => '5.00', 'total_cost' => '500.00', 'to_location_id' => $location->id, 'reference_type' => 'work_order_output', 'reference_id' => $this->output->id, 'created_by' => $this->maker->id, 'lot_number' => $this->output->batch_code]);
        app(MovementGlPostingService::class)->postFor($receipt);
        $this->assertTrue($receipt->fresh()->gl_handoff_status?->isResolved());
        $this->output->forceFill(['production_receipt_movement_id' => $receipt->id])->save();
        StockLevel::create(['item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 100, 'reserved_quantity' => 0, 'weighted_avg_cost' => '5.00']);
        $movementCount = StockMovement::query()->where('item_id', $item->id)->count();
        $this->report();
        foreach (['reserve', 'issue', 'automatic_lot_issue', 'issue_across_lots'] as $action) {
            try {
                if ($action === 'reserve') {
                    app(StockMovementService::class)->reserve($item->id, $location->id, '1');
                } elseif ($action === 'issue_across_lots') {
                    app(StockMovementService::class)->issueAcrossLots(new StockMovementInput(
                        type: StockMovementType::MaterialIssue,
                        itemId: $item->id,
                        quantity: '1',
                        fromLocationId: $location->id,
                        referenceType: 'work_order',
                        referenceId: $this->wo->id,
                        createdBy: $this->maker->id,
                    ));
                } else {
                    app(StockMovementService::class)->move(new StockMovementInput(type: StockMovementType::MaterialIssue, itemId: $item->id, quantity: '1', fromLocationId: $location->id, createdBy: $this->maker->id, lotNumber: $action === 'automatic_lot_issue' ? null : $this->output->batch_code));
                }
                $this->fail('QC-held stock cannot be consumed by ordinary production.');
            } catch (BusinessRuleException $e) {
                $this->assertStringContainsString('QC', $e->getMessage());
            }
        }
        $this->assertSame('100.000', StockLevel::where('item_id', $item->id)->firstOrFail()->quantity);
        $this->assertSame('0.000', StockLevel::where('item_id', $item->id)->firstOrFail()->reserved_quantity);
        $this->assertSame($movementCount, StockMovement::query()->where('item_id', $item->id)->count());
    }

    public function test_repair_moves_existing_piece_once_without_increasing_total_stock(): void
    {
        [$item, $location] = $this->receiveOriginal();
        $ncr = $this->sort($this->report());
        $repair = WorkOrder::findOrFail($ncr->rework_work_order_id);
        $crew = $this->repairOutputStartingState($repair);
        $repairedOutput = app(WorkOrderOutputService::class)->record($repair, [
            'good_count' => 1, 'reject_count' => 0, 'crew_id' => $crew->id,
        ], $this->repairOperator->id, 'repair-1');
        $this->assertSame('100.000', StockLevel::where('item_id', $item->id)->firstOrFail()->quantity);
        $this->assertDatabaseHas('stock_movements', ['reference_type' => 'work_order_output', 'reference_id' => $repairedOutput->id, 'movement_type' => 'adjustment_out', 'lot_number' => $this->output->batch_code, 'quantity' => '1.000']);
        $this->assertSame('0.000', app(QualityStockHoldService::class)->heldQuantity($item->id, $location->id, $this->output->batch_code));
        $this->assertSame('1.000', app(QualityStockHoldService::class)->heldQuantity($item->id, $location->id));
        app(StockMovementService::class)->reserve($item->id, $location->id, '99');
        $this->assertSame('99.000', StockLevel::where('item_id', $item->id)->firstOrFail()->reserved_quantity);
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('QC-held');
        app(StockMovementService::class)->reserve($item->id, $location->id, '1');
    }

    public function test_in_process_failure_blocks_release_until_independent_full_recovery(): void
    {
        $process = Inspection::query()
            ->where('stage', 'in_process')
            ->where('entity_type', 'work_order')
            ->where('entity_id', $this->wo->id)
            ->whereNull('wo_operation_id')
            ->firstOrFail();
        $process->forceFill([
            'status' => 'failed',
            'accepted_quantity' => 0,
            'inspector_id' => $this->maker->id,
            'reviewed_by' => $this->checker->id,
            'reviewed_at' => now(),
        ])->save();
        $ncr = app(NcrService::class)->create(['source' => 'inspection_fail', 'severity' => 'critical', 'inspection_id' => $process->id, 'defect_description' => 'Process drift.', 'affected_quantity' => 100], $this->maker);
        $this->assertFalse($this->inspection->fresh()->canReleaseOutgoing());
        app(NcrService::class)->setDisposition($ncr, 'rework', 'Adjusted machine.', null, $this->approver, '100', ['inspected_quantity' => '100', 'performed_by' => $this->sorter->id, 'evidence_reference' => 'PROCESS-100', 'recovery_passed' => true]);
        $this->assertTrue($this->inspection->fresh()->canReleaseOutgoing());
    }

    public function test_checklist_process_recovery_verifies_sample_without_claiming_unproduced_batch(): void
    {
        $process = Inspection::query()
            ->where('stage', 'in_process')
            ->where('entity_type', 'work_order')
            ->where('entity_id', $this->wo->id)
            ->whereNull('wo_operation_id')
            ->firstOrFail();
        $process->forceFill([
            'capture_method' => 'checklist', 'source_quantity' => '100.000', 'source_uom_code' => 'PCS', 'sampling_basis' => 'piece', 'sample_size' => 5, 'inspected_quantity' => 5, 'sample_defect_count' => 1,
            'status' => 'failed',
            'accepted_quantity' => 0,
            'inspector_id' => $this->maker->id,
            'reviewed_by' => $this->checker->id,
            'reviewed_at' => now(),
        ])->save();
        $ncr = app(NcrService::class)->create(['source' => 'inspection_fail', 'severity' => 'critical', 'inspection_id' => $process->id, 'defect_description' => 'Process drift.', 'affected_quantity' => 1], $this->maker);
        $this->assertFalse($this->inspection->fresh()->canReleaseOutgoing());
        app(NcrService::class)->setDisposition($ncr, 'rework', 'Adjusted machine.', null, $this->approver, '5', ['inspected_quantity' => '5', 'performed_by' => $this->sorter->id, 'evidence_reference' => 'PROCESS-SAMPLE-5', 'recovery_passed' => true]);
        $this->assertTrue($this->inspection->fresh()->canReleaseOutgoing());
    }

    public function test_repair_ncr_closes_only_after_checked_repair_output(): void
    {
        $ncr = $this->sort($this->report());
        $repair = WorkOrder::findOrFail($ncr->rework_work_order_id);
        $repair->forceFill(['status' => 'completed', 'quantity_good' => 1, 'quantity_produced' => 1])->save();
        $output = WorkOrderOutput::create(['work_order_id' => $repair->id, 'recorded_by' => $this->maker->id, 'recorded_at' => now(), 'good_count' => 1, 'reject_count' => 0, 'batch_code' => 'REPAIR-1']);
        Inspection::create(['inspection_number' => 'QC-REPAIR-1', 'stage' => 'outgoing', 'status' => 'passed', 'product_id' => $this->product->id, 'entity_type' => 'work_order', 'entity_id' => $repair->id, 'work_order_output_id' => $output->id, 'batch_quantity' => 1, 'sample_size' => 1, 'accepted_quantity' => 1, 'inspector_id' => $this->maker->id, 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()]);
        foreach (['corrective', 'preventive'] as $type) {
            app(NcrService::class)->addAction($ncr, ['action_type' => $type, 'description' => 'Verified repair action.'], $this->maker);
        }
        $closed = app(NcrService::class)->close($ncr, $this->approver);
        $this->assertSame('closed', $closed->status->value);
        $this->assertSame(1, WorkOrder::where('parent_ncr_id', $ncr->id)->count());
    }

    public function test_scrap_removes_only_confirmed_bad_piece_from_original_lot(): void
    {
        [$item] = $this->receiveOriginal();
        $ncr = $this->sort($this->report(), 'scrap');
        foreach (['corrective', 'preventive'] as $type) {
            app(NcrService::class)->addAction($ncr, ['action_type' => $type, 'description' => 'Scrap documented.'], $this->maker);
        }
        app(NcrService::class)->close($ncr, $this->approver);
        $this->assertSame('99.000', StockLevel::where('item_id', $item->id)->firstOrFail()->quantity);
    }

    private function receiveOriginal(): array
    {
        $zone = WarehouseZone::factory()->create(['zone_type' => 'finished_goods']);
        $location = WarehouseLocation::factory()->create(['zone_id' => $zone->id]);
        $item = Item::factory()->create(['code' => $this->product->part_number, 'item_type' => 'finished_good']);
        $receipt = StockMovement::create(['movement_type' => 'production_receipt', 'item_id' => $item->id, 'quantity' => '100.000', 'unit_cost' => '5.00', 'total_cost' => '500.00', 'to_location_id' => $location->id, 'reference_type' => 'work_order_output', 'reference_id' => $this->output->id, 'created_by' => $this->maker->id, 'lot_number' => $this->output->batch_code]);
        app(MovementGlPostingService::class)->postFor($receipt);
        $this->assertTrue($receipt->fresh()->gl_handoff_status?->isResolved());
        $this->output->forceFill(['production_receipt_movement_id' => $receipt->id])->save();
        StockLevel::create(['item_id' => $item->id, 'location_id' => $location->id, 'quantity' => 100, 'reserved_quantity' => 0, 'weighted_avg_cost' => '5.00']);

        return [$item, $location];
    }

    public function test_repeated_scrap_sorts_only_remaining_original_pieces(): void
    {
        [$item] = $this->receiveOriginal();
        $first = $this->sort($this->report(), 'scrap');
        foreach (['corrective', 'preventive'] as $type) {
            app(NcrService::class)->addAction($first, ['action_type' => $type, 'description' => 'Documented disposition.'], $this->maker);
        }
        app(NcrService::class)->close($first, $this->approver);
        $second = $this->report();
        $this->assertSame(99, (int) $second->recovery_batch_quantity);
        $second = app(NcrService::class)->setDisposition($second, 'rework', 'Second defect.', null, $this->approver, '98', ['inspected_quantity' => '99', 'evidence_reference' => 'SORT-99', 'performed_by' => $this->sorter->id, 'recovery_passed' => true]);
        $this->assertSame(1, (int) $second->reworkWorkOrder->quantity_target);
        $this->assertSame(98, $this->inspection->fresh()->accepted_quantity);
        $this->assertTrue($this->inspection->fresh()->canReleaseOutgoing());
        $this->assertSame('99.000', StockLevel::where('item_id', $item->id)->firstOrFail()->quantity);
    }

    public function test_late_defect_sorts_only_unshipped_pieces_and_preserves_delivered_credit(): void
    {
        [$item, $location] = $this->receiveOriginal();
        StockMovement::create(['movement_type' => 'delivery', 'item_id' => $item->id, 'quantity' => '70', 'unit_cost' => '5.00', 'total_cost' => '350.00', 'from_location_id' => $location->id, 'created_by' => $this->maker->id, 'lot_number' => $this->output->batch_code]);
        StockLevel::where('item_id', $item->id)->update(['quantity' => 30]);
        $ncr = $this->report();
        $this->assertSame(30, (int) $ncr->recovery_batch_quantity);
        $ncr = app(NcrService::class)->setDisposition($ncr, 'rework', 'Late defect.', null, $this->approver, '29', ['inspected_quantity' => '30', 'evidence_reference' => 'SORT-30', 'performed_by' => $this->sorter->id, 'recovery_passed' => true]);
        $this->assertSame(1, (int) $ncr->reworkWorkOrder->quantity_target);
        $this->assertSame(99, $this->inspection->fresh()->accepted_quantity);
        $this->assertSame('1.000', app(QualityStockHoldService::class)->heldQuantity($item->id, $location->id));
    }

    public function test_failed_repair_consumes_reject_and_completes_without_fabricated_good_output(): void
    {
        [$item] = $this->receiveOriginal();
        $ncr = $this->sort($this->report());
        $repair = WorkOrder::findOrFail($ncr->rework_work_order_id);
        $crew = $this->repairOutputStartingState($repair);
        $defect = DefectType::create(['code' => 'REPAIRFAIL', 'name' => 'Repair unsuccessful', 'is_active' => true]);
        app(WorkOrderOutputService::class)->record($repair, [
            'good_count' => 0, 'reject_count' => 1, 'crew_id' => $crew->id,
            'defects' => [['defect_type_id' => $defect->id, 'count' => 1]],
        ], $this->repairOperator->id, 'repair-reject-1');
        app(WorkOrderService::class)->complete($repair->fresh());
        foreach (['corrective', 'preventive'] as $type) {
            app(NcrService::class)->addAction($ncr, ['action_type' => $type, 'description' => 'Repair failed; replacement required.'], $this->maker);
        }
        $closed = app(NcrService::class)->close($ncr->fresh(), $this->approver);
        $this->assertSame('closed', $closed->status->value);
        $this->assertSame('99.000', StockLevel::where('item_id', $item->id)->firstOrFail()->quantity);
        $remaining = new \ReflectionMethod(MrpEngineService::class, 'remainingToProduce');
        $this->assertSame(1.0, $remaining->invoke(app(MrpEngineService::class), $this->line));
    }

    public function test_scrap_cannot_close_without_traceable_stock_receipt(): void
    {
        $ncr = $this->sort($this->report(), 'scrap');
        foreach (['corrective', 'preventive'] as $type) {
            app(NcrService::class)->addAction($ncr, ['action_type' => $type, 'description' => 'Stock must reconcile.'], $this->maker);
        }
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('traceable');
        app(NcrService::class)->close($ncr, $this->approver);
    }

    public function test_pending_repair_does_not_generate_manufacturing_material_demand(): void
    {
        Event::fake([MrpPlanGenerated::class]);
        $ncr = $this->sort($this->report());
        $material = Item::factory()->create();
        $bom = Bom::create(['product_id' => $this->product->id, 'version' => 1, 'is_active' => true]);
        BomItem::create(['bom_id' => $bom->id, 'item_id' => $material->id, 'quantity_per_unit' => '1.0000', 'unit' => $material->unit_of_measure, 'waste_factor' => '0.00', 'sort_order' => 0]);
        $order = $this->line->salesOrder;
        $order->forceFill(['status' => 'confirmed'])->save();
        $plan = app(MrpEngineService::class)->runForSalesOrder($order);
        $this->assertSame([], $plan->cost_summary['products']);
        $this->assertSame(0, (int) $plan->auto_pr_count);
        $this->assertSame(2, WorkOrder::where('sales_order_item_id', $this->line->id)->count());
        $this->assertNotNull($ncr->rework_work_order_id);
    }

    public function test_linked_repair_cannot_be_cancelled_and_strand_its_ncr(): void
    {
        $ncr = $this->sort($this->report());
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('cannot be cancelled');
        app(WorkOrderService::class)->cancel($ncr->reworkWorkOrder, 'Cancel repair');
    }

    public function test_existing_delivery_reservation_cannot_ship_a_later_rejected_piece(): void
    {
        [$item, $location] = $this->receiveOriginal();
        $order = $this->line->salesOrder;
        $order->forceFill(['status' => 'confirmed'])->save();
        $delivery = app(DeliveryService::class)->create([
            'sales_order_id' => $order->id, 'scheduled_date' => now()->toDateString(),
            'items' => [['sales_order_item_id' => $this->line->id, 'inspection_id' => $this->inspection->id, 'quantity' => '100']],
        ], $this->maker);
        $reservation = $delivery->stockReservationBatch->reservations->firstOrFail();
        $line = $delivery->items->firstOrFail();
        $this->sort($this->report());
        $delivery->forceFill(['status' => 'in_transit'])->save();
        try {
            app(StockMovementService::class)->move(new StockMovementInput(
                type: StockMovementType::Delivery, itemId: $item->id, quantity: '100', fromLocationId: $location->id,
                referenceType: 'delivery_item', referenceId: $line->id, createdBy: $this->maker->id,
                lotNumber: $this->output->batch_code, deliveryStockReservationId: $reservation->id,
            ));
            $this->fail('The old reservation must not ship a newly rejected piece.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('QC', $e->getMessage());
        }
        $this->assertSame('100.000', StockLevel::where('item_id', $item->id)->firstOrFail()->quantity);
    }
}
