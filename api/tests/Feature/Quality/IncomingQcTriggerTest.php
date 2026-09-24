<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Events\GoodsReceiptNoteCreated;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Listeners\TriggerIncomingQC;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\ItemQualityPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomingQcTriggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_grn_item_creates_an_incoming_inspection_without_misusing_product_id(): void
    {
        $role = Role::create(['name' => 'Incoming QC Test', 'slug' => 'incoming-qc-test']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'created_by' => $user->id,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Incoming raw material',
            'quantity' => '25.000',
            'unit' => 'kg',
            'unit_price' => '10.00',
            'total' => '250.00',
            'quantity_received' => '25.000',
        ]);
        $grn = GoodsReceiptNote::create([
            'grn_number' => 'GRN-INCOMING-QC',
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'received_date' => now()->toDateString(),
            'received_by' => $user->id,
            'status' => GrnStatus::PendingQc,
        ]);
        GrnItem::create([
            'goods_receipt_note_id' => $grn->id,
            'purchase_order_item_id' => $poItem->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_received' => '25.000',
            'quantity_accepted' => '0.000',
            'unit_cost' => '10.00',
        ]);

        app(TriggerIncomingQC::class)->handle(new GoodsReceiptNoteCreated($grn));

        $this->assertDatabaseHas('inspections', [
            'stage' => 'incoming',
            'entity_type' => 'grn',
            'entity_id' => $grn->id,
            'item_id' => $item->id,
            'product_id' => null,
        ]);
        $this->assertNotNull($grn->fresh()->qc_inspection_id);
        // No plan → lot checklist scaffold (ticked checks), not a per-unit sheet.
        $this->assertDatabaseHas('inspections', [
            'id' => $grn->fresh()->qc_inspection_id,
            'inspection_mode' => 'lot_checklist',
        ]);
        $this->assertDatabaseHas('inspection_measurements', [
            'inspection_id' => $grn->fresh()->qc_inspection_id,
            'parameter_name' => 'Delivery documents (DR/invoice) match the PO item and quantity',
            'is_pass' => null,
        ]);
    }

    public function test_effective_item_plan_builds_versioned_measurement_scaffold(): void
    {
        $role = Role::create(['name' => 'Planned QC Test', 'slug' => 'planned-qc-test']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();
        $po = PurchaseOrder::factory()->create(['status' => PurchaseOrderStatus::Approved->value, 'created_by' => $user->id]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'item_id' => $item->id, 'description' => 'Planned material',
            'quantity' => '10.000', 'unit' => 'kg', 'unit_price' => '8.00', 'total' => '80.00', 'quantity_received' => '10.000',
        ]);
        $grn = GoodsReceiptNote::create([
            'grn_number' => 'GRN-PLANNED-QC', 'purchase_order_id' => $po->id, 'vendor_id' => $po->vendor_id,
            'received_date' => now()->toDateString(), 'received_by' => $user->id, 'status' => GrnStatus::PendingQc,
        ]);
        $line = GrnItem::create([
            'goods_receipt_note_id' => $grn->id, 'purchase_order_item_id' => $poItem->id,
            'item_id' => $item->id, 'location_id' => $location->id, 'quantity_received' => '10.000',
            'quantity_accepted' => '0.000', 'unit_cost' => '8.00',
        ]);
        $plan = ItemQualityPlan::create([
            'item_id' => $item->id, 'vendor_id' => null, 'version' => 1, 'stage' => 'incoming',
            'sampling_method' => 'fixed', 'fixed_sample_size' => 2, 'parameters' => [[
                'parameter_name' => 'Moisture', 'parameter_type' => 'dimensional',
                'unit_of_measure' => '%', 'tolerance_min' => 0, 'tolerance_max' => 0.2, 'is_critical' => true,
            ]],
            'effective_from' => now()->toDateString(), 'is_active' => true, 'created_by' => $user->id,
        ]);

        app(TriggerIncomingQC::class)->handle(new GoodsReceiptNoteCreated($grn));

        $this->assertDatabaseHas('inspections', [
            'grn_item_id' => $line->id, 'item_quality_plan_id' => $plan->id,
            'sample_size' => 2, 'item_id' => $item->id,
        ]);
        $inspectionId = $grn->fresh()->qc_inspection_id;
        $this->assertSame(2, InspectionMeasurement::where('inspection_id', $inspectionId)->count());
        $this->assertDatabaseHas('inspection_measurements', ['inspection_id' => $inspectionId, 'parameter_name' => 'Moisture']);
    }

    public function test_incoming_qc_scaffolds_inspection_for_fractional_and_archived_items(): void
    {
        $user = User::factory()->create();
        $archivedItem = Item::factory()->create();
        $archivedItem->delete(); // Soft-deleted/archived item

        $location = WarehouseLocation::factory()->create();
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'created_by' => $user->id,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $archivedItem->id,
            'description' => 'Archived chemical',
            'quantity' => '1.500',
            'unit' => 'kg',
            'unit_price' => '20.00',
            'total' => '30.00',
            'quantity_received' => '1.500',
        ]);
        $grn = GoodsReceiptNote::create([
            'grn_number' => 'GRN-FRAC-ARCH',
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'received_date' => now()->toDateString(),
            'received_by' => $user->id,
            'status' => GrnStatus::PendingQc,
        ]);
        $line = GrnItem::create([
            'goods_receipt_note_id' => $grn->id,
            'purchase_order_item_id' => $poItem->id,
            'item_id' => $archivedItem->id,
            'location_id' => $location->id,
            'quantity_received' => '1.500',
            'quantity_accepted' => '0.000',
            'unit_cost' => '20.00',
        ]);

        app(TriggerIncomingQC::class)->handle(new GoodsReceiptNoteCreated($grn));

        $inspection = \App\Modules\Quality\Models\Inspection::query()
            ->where('grn_item_id', $line->id)
            ->first();

        $this->assertNotNull($inspection, 'Archived item must still have an incoming inspection generated.');
        $this->assertSame(2, (int) $inspection->batch_quantity, 'Fractional quantity 1.500 must round up to whole-unit ceiling 2.');
    }
}
