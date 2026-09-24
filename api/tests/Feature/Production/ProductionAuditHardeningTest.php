<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Traits\HasAuditLog;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\Production\Enums\MachineDowntimeCategory;
use App\Modules\Production\Enums\WoOperationStatus;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Models\MachineDowntime;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\WoOperation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderDefect;
use App\Modules\Production\Models\WorkOrderMaterial;
use App\Modules\Production\Services\WoOperationService;
use App\Modules\Production\Services\WorkOrderService;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ProductionAuditHardeningTest extends TestCase
{
    use RefreshDatabase;

    private WorkOrderService $service;

    private WoOperationService $opService;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();

        $this->service = app(WorkOrderService::class);
        $this->opService = app(WoOperationService::class);
        $this->user = User::factory()->create();
        $this->product = Product::create([
            'part_number' => 'HARD-P-'.substr(uniqid(), -5),
            'name' => 'Hardening Product',
            'unit_of_measure' => 'pcs',
            'standard_cost' => 15.00,
            'is_active' => true,
        ]);
    }

    private function machine(): Machine
    {
        return Machine::factory()->create(['status' => 'idle']);
    }

    private function mold(): Mold
    {
        return Mold::create([
            'mold_code' => 'MD-'.substr(uniqid(), -5),
            'name' => 'Hardening Mold',
            'product_id' => $this->product->id,
            'cavity_count' => 2,
            'cycle_time_seconds' => 25,
            'output_rate_per_hour' => 120,
            'setup_time_minutes' => 10,
            'current_shot_count' => 0,
            'max_shots_before_maintenance' => 100000,
            'lifetime_max_shots' => 1000000,
            'status' => 'available',
        ]);
    }

    private function startedWo(Machine $machine, Mold $mold): WorkOrder
    {
        $mold->compatibleMachines()->syncWithoutDetaching([$machine->id]);

        $wo = WorkOrder::factory()->create([
            'product_id' => $this->product->id,
            'machine_id' => $machine->id,
            'mold_id' => $mold->id,
            'status' => WorkOrderStatus::Planned->value,
            'quantity_target' => 100,
            'planned_start' => Carbon::today()->addDay()->toDateTimeString(),
            'planned_end' => Carbon::today()->addDays(2)->toDateTimeString(),
            'work_order_class' => 'non_stock',
            'exception_reason' => 'Audit hardening non-stock WO',
            'exception_authorized_by' => $this->user->id,
            'created_by' => $this->user->id,
        ]);

        return $this->service->start($this->service->confirm($wo), $this->user->id);
    }

    public function test_resume_refused_when_machine_went_to_breakdown_during_pause(): void
    {
        $machine = $this->machine();
        $mold = $this->mold();

        $wo = $this->startedWo($machine, $mold);
        $paused = $this->service->pause($wo, 'Shift handover', MachineDowntimeCategory::Changeover);

        $machine->update(['status' => MachineStatus::Breakdown->value]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage("Assigned machine {$machine->machine_code} is currently breakdown and cannot resume production. Repair or restore the machine first.");

        $this->service->resume($paused);
    }

    public function test_resume_refused_when_machine_went_to_maintenance_during_pause(): void
    {
        $machine = $this->machine();
        $mold = $this->mold();

        $wo = $this->startedWo($machine, $mold);
        $paused = $this->service->pause($wo, 'Routine inspection', MachineDowntimeCategory::PlannedMaintenance);

        $machine->update(['status' => MachineStatus::Maintenance->value]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage("Assigned machine {$machine->machine_code} is currently maintenance and cannot resume production. Repair or restore the machine first.");

        $this->service->resume($paused);
    }

    public function test_pause_does_not_clear_another_work_orders_machine_or_revert_maintenance(): void
    {
        $machine = $this->machine();
        $mold = $this->mold();

        $wo = $this->startedWo($machine, $mold);

        // Machine is placed under maintenance by technician
        $machine->update([
            'status' => MachineStatus::Maintenance->value,
            'current_work_order_id' => null,
        ]);

        $paused = $this->service->pause($wo, 'Parts shortage', MachineDowntimeCategory::MaterialShortage);

        $this->assertSame(WorkOrderStatus::Paused, $paused->status);
        $this->assertSame(MachineStatus::Maintenance, $machine->fresh()->status, 'Machine in maintenance must not be reset to idle on pause');
        $this->assertNull($machine->fresh()->current_work_order_id);
    }

    public function test_complete_does_not_revert_maintenance_or_clear_another_work_order(): void
    {
        $machine = $this->machine();
        $mold = $this->mold();

        $wo = $this->startedWo($machine, $mold);

        // Machine enters breakdown/maintenance
        $machine->update([
            'status' => MachineStatus::Maintenance->value,
            'current_work_order_id' => null,
        ]);

        $completed = $this->service->complete($wo);

        $this->assertSame(WorkOrderStatus::Completed, $completed->status);
        $this->assertSame(MachineStatus::Maintenance, $machine->fresh()->status, 'Machine in maintenance must not be reset to idle on complete');
    }

    public function test_complete_refused_when_routing_operations_are_incomplete(): void
    {
        $machine = $this->machine();
        $mold = $this->mold();

        $wo = $this->startedWo($machine, $mold);

        $op = WoOperation::create([
            'work_order_id' => $wo->id,
            'sequence' => 10,
            'operation_name' => 'Injection Molding',
            'machine_id' => $machine->id,
            'mold_id' => $mold->id,
            'status' => WoOperationStatus::InProgress,
            'qty_planned' => 100,
            'qty_completed' => 0,
            'qty_scrapped' => 0,
        ]);

        try {
            $this->service->complete($wo);
            $this->fail('complete() must refuse completion when routing operations are still pending/in_progress.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('cannot be completed because some routing operations are still pending or in progress', $e->getMessage());
        }

        $this->assertSame(WorkOrderStatus::InProgress, $wo->fresh()->status);

        // Mark operation completed
        $op->update([
            'status' => WoOperationStatus::Completed,
            'actual_end' => Carbon::now(),
        ]);

        $completed = $this->service->complete($wo);
        $this->assertSame(WorkOrderStatus::Completed, $completed->status);
    }

    public function test_operation_output_cannot_exceed_previous_operation_completed_quantity(): void
    {
        $machine = $this->machine();
        $mold = $this->mold();

        $wo = $this->startedWo($machine, $mold);

        // Op 1 completed with 50 units
        $op1 = WoOperation::create([
            'work_order_id' => $wo->id,
            'sequence' => 10,
            'operation_name' => 'Molding',
            'machine_id' => $machine->id,
            'mold_id' => $mold->id,
            'status' => WoOperationStatus::Completed,
            'qty_planned' => 100,
            'qty_completed' => 50,
            'qty_scrapped' => 0,
            'actual_end' => Carbon::now(),
        ]);

        // Op 2 is in progress
        $op2 = WoOperation::create([
            'work_order_id' => $wo->id,
            'sequence' => 20,
            'operation_name' => 'Trimming',
            'machine_id' => $machine->id,
            'mold_id' => $mold->id,
            'status' => WoOperationStatus::InProgress,
            'qty_planned' => 100,
            'qty_completed' => 0,
            'qty_scrapped' => 0,
        ]);

        try {
            $this->opService->recordOutput($op2, 60.0);
            $this->fail('recordOutput must refuse recording output exceeding previous operation completed count.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('exceeding previous operation 10 completed quantity of 50', $e->getMessage());
        }

        // Recording 50 succeeds
        $this->opService->recordOutput($op2, 50.0);
        $this->assertSame('50.0000', (string) $op2->fresh()->qty_completed);
    }

    public function test_material_lot_references_captures_actual_issued_stock_movement_lots(): void
    {
        $warehouse = Warehouse::factory()->create();
        $zone = WarehouseZone::factory()->create([
            'warehouse_id' => $warehouse->id,
            'zone_type' => 'raw_materials',
        ]);
        $location = WarehouseLocation::factory()->create([
            'zone_id' => $zone->id,
        ]);

        $rawItem = Item::factory()->create([
            'code' => 'RESIN-'.substr(uniqid(), -5),
            'name' => 'POM Resin Grade A',
            'unit_of_measure' => 'kg',
            'standard_cost' => 120.00,
            'is_active' => true,
        ]);

        // Stock movement with specific lot number LOT-REAL-001
        StockMovement::create([
            'item_id' => $rawItem->id,
            'from_location_id' => null,
            'to_location_id' => $location->id,
            'movement_type' => StockMovementType::GrnReceipt,
            'quantity' => '500.000',
            'unit_cost' => '120.0000',
            'total_cost' => '60000.00',
            'lot_number' => 'LOT-REAL-001',
            'created_by' => $this->user->id,
            'created_at' => Carbon::now()->subDays(2),
        ]);

        StockLevel::create([
            'item_id' => $rawItem->id,
            'location_id' => $location->id,
            'quantity' => '500.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '120.0000',
        ]);

        $po = PurchaseOrder::factory()->create();
        $poItem1 = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $rawItem->id,
            'description' => 'POM Resin',
            'quantity' => '500.00',
            'unit' => 'kg',
            'unit_price' => '120.00',
            'total' => '60000.00',
            'quantity_received' => '500.00',
            'quantity_accepted' => '500.00',
        ]);
        $poItem2 = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $rawItem->id,
            'description' => 'POM Resin',
            'quantity' => '100.00',
            'unit' => 'kg',
            'unit_price' => '120.00',
            'total' => '12000.00',
            'quantity_received' => '100.00',
            'quantity_accepted' => '100.00',
        ]);

        $grn = GoodsReceiptNote::factory()->create(['status' => 'accepted']);
        $grnItem = GrnItem::create([
            'goods_receipt_note_id' => $grn->id,
            'item_id' => $rawItem->id,
            'purchase_order_item_id' => $poItem1->id,
            'quantity_received' => '500.000',
            'quantity_accepted' => '500.000',
            'unit_cost' => '120.0000',
            'material_lot_number' => 'LOT-REAL-001',
            'supplier_lot_reference' => 'SUP-LOT-XYZ',
            'location_id' => $location->id,
        ]);

        // Newer GRN exists for the same item with LOT-NEW-999
        $newerGrn = GoodsReceiptNote::factory()->create(['status' => 'accepted']);
        GrnItem::create([
            'goods_receipt_note_id' => $newerGrn->id,
            'item_id' => $rawItem->id,
            'purchase_order_item_id' => $poItem2->id,
            'quantity_received' => '100.000',
            'quantity_accepted' => '100.000',
            'unit_cost' => '120.0000',
            'material_lot_number' => 'LOT-NEW-999',
            'supplier_lot_reference' => 'SUP-LOT-NEW',
            'location_id' => $location->id,
        ]);

        $machine = $this->machine();
        $mold = $this->mold();
        $mold->compatibleMachines()->syncWithoutDetaching([$machine->id]);

        $wo = WorkOrder::factory()->create([
            'product_id' => $this->product->id,
            'machine_id' => $machine->id,
            'mold_id' => $mold->id,
            'status' => WorkOrderStatus::Planned->value,
            'quantity_target' => 50,
            'created_by' => $this->user->id,
        ]);

        WorkOrderMaterial::create([
            'work_order_id' => $wo->id,
            'item_id' => $rawItem->id,
            'bom_quantity' => '50.000',
            'standard_cost' => '6000.00',
        ]);

        // Confirm WO (creates reservation)
        $confirmed = $this->service->confirm($wo);

        // Start WO (issues materials and captures lot references)
        $started = $this->service->start($confirmed, $this->user->id);

        $refs = $started->fresh()->material_lot_references;
        $this->assertNotEmpty($refs);
        $this->assertSame('LOT-REAL-001', $refs[0]['material_lot_number'], 'Material lot references must capture the actual issued lot, not the newest GRN item');
        $this->assertSame('SUP-LOT-XYZ', $refs[0]['supplier_lot_reference']);
    }

    public function test_production_models_use_has_audit_log_trait(): void
    {
        $models = [
            WoOperation::class,
            MachineDowntime::class,
            WorkOrderDefect::class,
            DefectType::class,
            ProductionSchedule::class,
        ];

        foreach ($models as $modelClass) {
            $traits = class_uses_recursive($modelClass);
            $this->assertArrayHasKey(
                HasAuditLog::class,
                $traits,
                "Model {$modelClass} must use HasAuditLog trait."
            );
        }
    }
}
