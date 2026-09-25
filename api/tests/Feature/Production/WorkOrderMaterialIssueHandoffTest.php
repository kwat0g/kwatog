<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\MaterialIssueStatus;
use App\Modules\Inventory\Enums\ReservationStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\MaterialReservation;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\MaterialIssueService;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\MachineDowntimeCategory;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderMaterial;
use App\Modules\Production\Models\WoOperation;
use App\Modules\Production\Resources\WorkOrderResource;
use App\Modules\Production\Services\WorkOrderOutputService;
use App\Modules\Production\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Tests\TestCase;

class WorkOrderMaterialIssueHandoffTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    private Item $item;

    private WarehouseLocation $location;

    private WorkOrderService $workOrders;

    private MaterialIssueService $materialIssues;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();

        $this->user = User::factory()->create();
        $this->product = Product::create([
            'part_number' => 'WO-ISSUE-'.substr(uniqid(), -5),
            'name' => 'Material handoff product',
            'unit_of_measure' => 'pcs',
            'standard_cost' => '15.00',
            'is_active' => true,
        ]);
        $this->item = Item::factory()->create([
            'code' => 'MI-'.substr(uniqid(), -5),
            'name' => 'Resin for material handoff',
            'unit_of_measure' => 'kg',
            'standard_cost' => '10.0000',
            'is_active' => true,
        ]);
        $this->location = WarehouseLocation::factory()->create([
            'code' => 'MI-'.substr(uniqid(), -5),
        ]);
        StockLevel::create([
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity' => '100.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '12.3400',
        ]);

        $this->workOrders = app(WorkOrderService::class);
        $this->materialIssues = app(MaterialIssueService::class);
    }

    private function plannedWorkOrder(string $bomQuantity = '10.000'): WorkOrder
    {
        $workOrder = WorkOrder::factory()->create([
            'product_id' => $this->product->id,
            'status' => WorkOrderStatus::Planned->value,
            'quantity_target' => 10,
            'planned_start' => Carbon::today()->addDay()->toDateTimeString(),
            'planned_end' => Carbon::today()->addDays(2)->toDateTimeString(),
            'work_order_class' => 'standard',
            'material_plan_source' => 'bom',
            'created_by' => $this->user->id,
        ]);
        WorkOrderMaterial::create([
            'work_order_id' => $workOrder->id,
            'item_id' => $this->item->id,
            'bom_quantity' => $bomQuantity,
            'standard_unit_cost' => '10.0000',
            'standard_cost' => bcmul($bomQuantity, '10.0000', 2),
            'actual_quantity_issued' => '0.000',
            'actual_cost' => '0.00',
            'cost_variance' => '0.00',
            'variance' => '0.000',
        ]);

        return $workOrder;
    }

    private function confirm(WorkOrder $workOrder): WorkOrder
    {
        $machine = Machine::factory()->create(['status' => 'idle']);
        $mold = Mold::create([
            'mold_code' => 'MI-MD-'.substr(uniqid(), -5),
            'name' => 'Material handoff mold',
            'product_id' => $this->product->id,
            'cavity_count' => 1,
            'cycle_time_seconds' => 25,
            'output_rate_per_hour' => 120,
            'setup_time_minutes' => 10,
            'current_shot_count' => 0,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 1000000,
            'status' => 'available',
        ]);
        $mold->compatibleMachines()->syncWithoutDetaching([$machine->id]);

        return $this->workOrders->confirm($workOrder, $machine->id, $mold->id);
    }

    private function issue(
        WorkOrder $workOrder,
        string $quantity,
        ?MaterialReservation $reservation = null,
        ?string $lot = null,
        ?WarehouseLocation $source = null,
    ): \App\Modules\Inventory\Models\MaterialIssueSlip {
        return $this->materialIssues->create([
            'work_order_id' => $workOrder->hash_id,
            'issued_date' => now()->toDateString(),
            'items' => [[
                'item_id' => $this->item->hash_id,
                'location_id' => ($source ?? $this->location)->id,
                'quantity_issued' => $quantity,
                'material_reservation_id' => $reservation?->id,
                'lot_number' => $lot,
        ]],
    ], $this->user);
    }

    private function addStockLocation(string $quantity = '100.000'): WarehouseLocation
    {
        $location = WarehouseLocation::factory()->create([
            'code' => 'MI-'.substr(uniqid(), -5),
        ]);
        StockLevel::create([
            'item_id' => $this->item->id,
            'location_id' => $location->id,
            'quantity' => $quantity,
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '12.3400',
        ]);

        return $location;
    }

    private function level(): StockLevel
    {
        return StockLevel::query()
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->firstOrFail();
    }

    public function test_start_accounts_for_general_material_issue_instead_of_issuing_the_full_bom_again(): void
    {
        $workOrder = $this->confirm($this->plannedWorkOrder());
        $this->issue($workOrder, '10.000');

        $started = $this->workOrders->start($workOrder, $this->user->id);

        $this->assertSame(WorkOrderStatus::InProgress, $started->status);
        $this->assertSame('90.000', (string) $this->level()->quantity);
        $this->assertSame('0.000', (string) $this->level()->reserved_quantity);
        $this->assertSame(1, StockMovement::query()
            ->where('item_id', $this->item->id)
            ->where('movement_type', StockMovementType::MaterialIssue->value)
            ->count());

        $detail = WorkOrderResource::make($this->workOrders->show($started))->resolve();
        $this->assertSame('10.000', $detail['materials'][0]['actual_quantity_issued']);
        $this->assertSame('123.40', $detail['materials'][0]['actual_cost']);
    }

    public function test_cancelled_reserved_issue_leaves_short_coverage_until_warehouse_reissues_the_gap(): void
    {
        $workOrder = $this->confirm($this->plannedWorkOrder('20.000'));
        $reservation = MaterialReservation::query()
            ->where('work_order_id', $workOrder->id)
            ->where('status', ReservationStatus::Reserved->value)
            ->firstOrFail();
        $slip = $this->issue($workOrder, '5.000', $reservation);
        $this->materialIssues->cancel($slip, $this->user);

        try {
            $this->workOrders->start($workOrder, $this->user->id);
            $this->fail('Starting after a reserved issue was cancelled must require the five-unit shortfall to be reissued.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('5.000', $e->getMessage());
        }

        $this->assertSame(WorkOrderStatus::Confirmed, $workOrder->fresh()->status);
        $this->assertSame('100.000', (string) $this->level()->quantity);
        $this->assertSame('15.000', (string) $this->level()->reserved_quantity);

        // Inventory's existing policy is intentional: cancelling the issue
        // returns stock but does not reopen the reduced reservation. Warehouse
        // reissues the missing five units from free stock before start.
        $this->issue($workOrder, '5.000');
        $started = $this->workOrders->start($workOrder->fresh(), $this->user->id);

        $this->assertSame(WorkOrderStatus::InProgress, $started->status);
        $this->assertSame('80.000', (string) $this->level()->quantity);
        $this->assertSame('0.000', (string) $this->level()->reserved_quantity);
        $this->assertSame(MaterialIssueStatus::Cancelled, $slip->fresh()->status);
        $this->assertSame('15.000', (string) WorkOrderMaterial::query()
            ->where('work_order_id', $workOrder->id)
            ->value('actual_quantity_issued'));
    }

    public function test_detail_and_output_lineage_use_the_actual_manual_issue_lot_and_cost(): void
    {
        StockMovement::create([
            'item_id' => $this->item->id,
            'from_location_id' => null,
            'to_location_id' => $this->location->id,
            'movement_type' => StockMovementType::GrnReceipt->value,
            'quantity' => '10.000',
            'unit_cost' => '12.3400',
            'total_cost' => '123.40',
            'lot_number' => 'LOT-WO-MANUAL-001',
            'created_by' => $this->user->id,
            'created_at' => Carbon::now()->subDay(),
        ]);
        $workOrder = $this->confirm($this->plannedWorkOrder());
        $this->issue($workOrder, '10.000', null, 'LOT-WO-MANUAL-001');

        $started = $this->workOrders->start($workOrder, $this->user->id);
        $detail = WorkOrderResource::make($this->workOrders->show($started))->resolve();
        $this->assertSame('10.000', $detail['materials'][0]['actual_quantity_issued']);
        $this->assertSame('123.40', $detail['material_cost_summary']['actual_cost']);
        $this->assertSame('LOT-WO-MANUAL-001', $detail['material_lot_references'][0]['material_lot_number']);

        $output = app(WorkOrderOutputService::class)->record($started, [
            'good_count' => 1,
            'reject_count' => 0,
            'defects' => [],
        ], $this->user->id, 'material-lineage-output');

        $lineage = $output->fresh()->material_lineage;
        $this->assertSame('10.000', $lineage['materials'][0]['actual_quantity_issued']);
        $this->assertSame('123.40', $lineage['materials'][0]['actual_cost']);
        $this->assertSame('LOT-WO-MANUAL-001', $lineage['material_lot_references'][0]['material_lot_number']);
    }

    public function test_cancelling_a_work_order_issue_after_recorded_output_does_not_restore_stock(): void
    {
        $workOrder = $this->confirm($this->plannedWorkOrder());
        $started = $this->workOrders->start($workOrder, $this->user->id);
        $slip = $this->issue($started, '2.000');
        app(WorkOrderOutputService::class)->record($started, [
            'good_count' => 1,
            'reject_count' => 0,
            'defects' => [],
        ], $this->user->id, 'cancel-material-after-output');

        $quantityBefore = (string) $this->level()->quantity;
        $movementsBefore = StockMovement::query()->count();

        try {
            $this->materialIssues->cancel($slip, $this->user);
            $this->fail('An issue linked to a work order with recorded output cannot be reversed into available stock.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('recorded output', $e->getMessage());
        }

        $this->assertSame($quantityBefore, (string) $this->level()->quantity);
        $this->assertSame($movementsBefore, StockMovement::query()->count());
        $this->assertSame(MaterialIssueStatus::Issued, $slip->fresh()->status);
    }

    public function test_manual_reversal_preserves_covered_output_and_blocks_cumulative_shortage_until_reissue(): void
    {
        $workOrder = $this->confirm($this->plannedWorkOrder('20.000'));
        $reservation = MaterialReservation::query()
            ->where('work_order_id', $workOrder->id)
            ->where('status', ReservationStatus::Reserved->value)
            ->firstOrFail();
        $slip = $this->issue($workOrder, '5.000', $reservation);
        $started = $this->workOrders->start($workOrder, $this->user->id);
        $this->materialIssues->cancel($slip, $this->user);

        // Fifteen units remain issued against a saved two-unit-per-piece
        // recipe. Seven pieces are covered even after the unused slip returns.
        app(WorkOrderOutputService::class)->record($started, [
            'good_count' => 7,
            'reject_count' => 0,
            'defects' => [],
        ], $this->user->id, 'covered-output-after-preoutput-cancel');

        try {
            app(WorkOrderOutputService::class)->record($started->fresh(), [
                'good_count' => 3,
                'reject_count' => 0,
                'defects' => [],
            ], $this->user->id, 'output-after-preoutput-cancel');
            $this->fail('Cumulative production must not use the five units that were returned to stock.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('5.000', $e->getMessage());
        }
        $this->assertSame(7, (int) $started->fresh()->quantity_good);

        $paused = $this->workOrders->pause(
            $started->fresh(),
            'Warehouse reissue needed',
            MachineDowntimeCategory::MaterialShortage,
        );
        // Resume respects already-recorded production; the next output still
        // requires its additional material before it can be recorded.
        $resumed = $this->workOrders->resume($paused);

        $this->issue($resumed, '5.000');
        $output = app(WorkOrderOutputService::class)->record($resumed->fresh(), [
            'good_count' => 3,
            'reject_count' => 0,
            'defects' => [],
        ], $this->user->id, 'output-after-reissue');

        $this->assertSame('80.000', (string) $this->level()->quantity);
        $this->assertSame('15.000', (string) $this->workOrders->show($resumed)->materials[0]->actual_quantity_issued);
        $detail = WorkOrderResource::make($this->workOrders->show($resumed))->resolve();
        $this->assertSame('20.000', $detail['materials'][0]['actual_quantity_issued']);
        $this->assertSame(3, (int) $output->good_count);
        $this->assertSame(10, (int) $resumed->fresh()->quantity_good);
        $this->assertSame(MaterialIssueStatus::Cancelled, $slip->fresh()->status);
    }

    public function test_start_aggregates_repeated_bom_rows_and_applies_manual_issue_once_per_item(): void
    {
        $workOrder = $this->plannedWorkOrder('6.000');
        WorkOrderMaterial::create([
            'work_order_id' => $workOrder->id,
            'item_id' => $this->item->id,
            'bom_quantity' => '4.000',
            'standard_unit_cost' => '10.0000',
            'standard_cost' => '40.00',
            'actual_quantity_issued' => '0.000',
            'actual_cost' => '0.00',
            'cost_variance' => '-40.00',
            'variance' => '0.000',
        ]);
        $workOrder = $this->confirm($workOrder);
        $this->issue($workOrder, '10.000');

        $started = $this->workOrders->start($workOrder, $this->user->id);
        $detail = WorkOrderResource::make($this->workOrders->show($started))->resolve();

        $this->assertCount(1, $detail['materials']);
        $this->assertSame('10.000', $detail['materials'][0]['bom_quantity']);
        $this->assertSame('10.000', $detail['materials'][0]['manual_quantity_issued']);
        $this->assertSame('0.000', $detail['materials'][0]['auto_quantity_issued']);
        $this->assertSame('10.000', $detail['materials'][0]['actual_quantity_issued']);
        $this->assertSame('90.000', (string) $this->level()->quantity);
        $this->assertSame('0.000', (string) $this->level()->reserved_quantity);
        $this->assertSame(1, StockMovement::query()
            ->where('movement_type', StockMovementType::MaterialIssue->value)
            ->count());
    }

    public function test_start_requires_actual_issue_when_all_reservations_were_released(): void
    {
        $workOrder = $this->confirm($this->plannedWorkOrder());
        $reservation = MaterialReservation::query()
            ->where('work_order_id', $workOrder->id)
            ->where('status', ReservationStatus::Reserved->value)
            ->firstOrFail();
        app(\App\Modules\Inventory\Services\StockMovementService::class)
            ->release($this->item->id, $this->location->id, (string) $reservation->quantity);
        $reservation->update(['status' => ReservationStatus::Released, 'released_at' => now()]);

        try {
            $this->workOrders->start($workOrder, $this->user->id);
            $this->fail('A standard stock-producing WO cannot start with no issued material or reservation coverage.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('10.000', $e->getMessage());
        }

        $this->assertSame(WorkOrderStatus::Confirmed, $workOrder->fresh()->status);
        $this->assertSame('100.000', (string) $this->level()->quantity);
        $this->assertSame('0.000', (string) $this->level()->reserved_quantity);
        $this->assertSame(0, StockMovement::query()
            ->where('movement_type', StockMovementType::MaterialIssue->value)
            ->count());
    }

    public function test_blocked_receipt_location_reservation_is_released_without_breaking_outbound_issue(): void
    {
        $workOrder = $this->confirm($this->plannedWorkOrder());
        $this->location->update(['is_blocked' => true]);

        $started = $this->workOrders->start($workOrder, $this->user->id);

        $this->assertSame(WorkOrderStatus::InProgress, $started->status);
        $this->assertSame('90.000', (string) $this->level()->quantity);
        $this->assertSame('0.000', (string) $this->level()->reserved_quantity);
        $this->assertSame('10.000', (string) WorkOrderMaterial::query()
            ->where('work_order_id', $workOrder->id)
            ->sum('actual_quantity_issued'));
        $this->assertSame('0.000', (string) $this->level()->reserved_quantity);
    }

    public function test_inactive_location_hold_is_released_when_manual_issue_covers_bom(): void
    {
        $workOrder = $this->confirm($this->plannedWorkOrder());
        $source = $this->addStockLocation();
        $this->location->update(['is_active' => false]);
        $this->issue($workOrder, '10.000', null, null, $source);

        $started = $this->workOrders->start($workOrder, $this->user->id);

        $this->assertSame(WorkOrderStatus::InProgress, $started->status);
        $this->assertSame('100.000', (string) $this->level()->quantity);
        $this->assertSame('0.000', (string) $this->level()->reserved_quantity);
        $this->assertSame('90.000', (string) StockLevel::query()
            ->where('item_id', $this->item->id)
            ->where('location_id', $source->id)
            ->value('quantity'));
        $this->assertSame('0.000', (string) StockLevel::query()
            ->where('item_id', $this->item->id)
            ->where('location_id', $source->id)
            ->value('reserved_quantity'));
        $this->assertSame('0.000', (string) WorkOrderMaterial::query()
            ->where('work_order_id', $workOrder->id)
            ->sum('actual_quantity_issued'));
        $detail = WorkOrderResource::make($this->workOrders->show($started))->resolve();
        $this->assertSame('10.000', $detail['materials'][0]['manual_quantity_issued']);
        $this->assertSame('10.000', $detail['materials'][0]['actual_quantity_issued']);
    }

    public function test_stale_reservation_aggregate_does_not_consume_another_work_orders_hold(): void
    {
        $workOrder = $this->confirm($this->plannedWorkOrder());
        $otherWorkOrder = $this->confirm($this->plannedWorkOrder());
        $this->issue($workOrder, '10.000');
        $this->level()->update(['reserved_quantity' => '10.000']);
        $quantityBefore = (string) $this->level()->quantity;

        try {
            $this->workOrders->start($workOrder, $this->user->id);
            $this->fail('A stale aggregate hold cannot be attributed to either work order safely.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Reconcile the reservation ledger', $e->getMessage());
        }

        $this->assertSame(WorkOrderStatus::Confirmed, $workOrder->fresh()->status);
        $this->assertSame(WorkOrderStatus::Confirmed, $otherWorkOrder->fresh()->status);
        $this->assertSame($quantityBefore, (string) $this->level()->quantity);
        $this->assertSame('10.000', (string) $this->level()->reserved_quantity);
        $this->assertSame(ReservationStatus::Reserved, MaterialReservation::query()
            ->where('work_order_id', $otherWorkOrder->id)
            ->where('status', ReservationStatus::Reserved->value)
            ->firstOrFail()
            ->status);
    }

    public function test_reject_only_output_still_prevents_material_cancellation(): void
    {
        $workOrder = $this->confirm($this->plannedWorkOrder());
        $started = $this->workOrders->start($workOrder, $this->user->id);
        $slip = $this->issue($started, '2.000');
        $defect = DefectType::create([
            'code' => 'MI-'.substr(uniqid(), -5),
            'name' => 'Reject-only material guard',
            'is_active' => true,
        ]);
        app(WorkOrderOutputService::class)->record($started, [
            'good_count' => 0,
            'reject_count' => 1,
            'defects' => [['defect_type_id' => $defect->id, 'count' => 1]],
        ], $this->user->id, 'reject-only-material-guard');

        $movementsBefore = StockMovement::query()->count();
        try {
            $this->materialIssues->cancel($slip, $this->user);
            $this->fail('A reject-only output still consumed material and must block stock reversal.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('recorded output', $e->getMessage());
        }

        $this->assertSame($movementsBefore, StockMovement::query()->count());
        $this->assertSame(MaterialIssueStatus::Issued, $slip->fresh()->status);
    }

    public function test_routed_operation_output_also_prevents_material_cancellation(): void
    {
        $workOrder = $this->confirm($this->plannedWorkOrder());
        $started = $this->workOrders->start($workOrder, $this->user->id);
        $slip = $this->issue($started, '2.000');
        WoOperation::create([
            'work_order_id' => $workOrder->id,
            'sequence' => 1,
            'operation_name' => 'Molding',
            'status' => 'in_progress',
            'qty_planned' => '10.0000',
            'qty_completed' => '1.0000',
            'qty_scrapped' => '0.0000',
        ]);

        $movementsBefore = StockMovement::query()->count();
        try {
            $this->materialIssues->cancel($slip, $this->user);
            $this->fail('Routed-operation production must block reversal even before a WO output row exists.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('recorded output', $e->getMessage());
        }

        $this->assertSame($movementsBefore, StockMovement::query()->count());
        $this->assertSame(MaterialIssueStatus::Issued, $slip->fresh()->status);
    }

    public function test_cancelled_mis_suppresses_legacy_latest_grn_lot_fallback(): void
    {
        $workOrder = $this->confirm($this->plannedWorkOrder());
        $purchaseOrder = PurchaseOrder::factory()->create(['created_by' => $this->user->id]);
        $purchaseOrderItem = PurchaseOrderItem::create([
            'purchase_order_id' => $purchaseOrder->id,
            'item_id' => $this->item->id,
            'description' => 'Unrelated latest receipt lot',
            'quantity' => '10.00',
            'unit' => 'kg',
            'unit_price' => '12.34',
            'total' => '123.40',
            'quantity_received' => '10.00',
        ]);
        $grn = GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $purchaseOrder->id,
            'vendor_id' => $purchaseOrder->vendor_id,
            'received_by' => $this->user->id,
        ]);
        GrnItem::create([
            'goods_receipt_note_id' => $grn->id,
            'purchase_order_item_id' => $purchaseOrderItem->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity_received' => '10.000',
            'quantity_accepted' => '10.000',
            'unit_cost' => '12.3400',
            'material_lot_number' => 'LOT-UNRELATED-LATEST-GRN',
            'supplier_lot_reference' => 'SUPPLIER-UNRELATED',
        ]);
        StockMovement::create([
            'item_id' => $this->item->id,
            'from_location_id' => null,
            'to_location_id' => $this->location->id,
            'movement_type' => StockMovementType::GrnReceipt->value,
            'quantity' => '10.000',
            'unit_cost' => '12.3400',
            'total_cost' => '123.40',
            'lot_number' => 'LOT-ISSUED-THEN-CANCELLED',
            'created_by' => $this->user->id,
            'created_at' => Carbon::now()->subDay(),
        ]);
        $slip = $this->issue($workOrder, '10.000', null, 'LOT-ISSUED-THEN-CANCELLED');

        $this->assertCount(1, $workOrder->fresh()->material_lot_references);
        $this->materialIssues->cancel($slip, $this->user);

        $this->assertSame([], $workOrder->fresh()->material_lot_references);
    }
}
