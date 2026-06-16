<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Inventory\Services\MaterialIssueService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * OGAMI-012 — lot/batch traceability.
 *
 * Receive a GRN with a supplier lot → accept (stock posts with the lot) →
 * issue the same lot to a work order → lotHistory(item, lot) returns BOTH the
 * receipt and the issue movement, proving the lot is traceable GRN→issue.
 */
class LotTraceabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private GrnService $grnSvc;
    private MaterialIssueService $misSvc;
    private StockMovementService $movements;

    protected function setUp(): void
    {
        parent::setUp();

        // Auto-replenishment listener tries to create a PR for a system user
        // that doesn't exist in the isolated DB — suppress the side-effect.
        Event::fake([StockMovementCompleted::class]);

        $role = Role::firstOrCreate(['slug' => 'warehouse_staff'], ['name' => 'Warehouse Staff']);
        $this->user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        $this->grnSvc    = app(GrnService::class);
        $this->misSvc    = app(MaterialIssueService::class);
        $this->movements = app(StockMovementService::class);
    }

    public function test_lot_flows_from_grn_receipt_through_to_material_issue(): void
    {
        $item     = Item::factory()->create(['is_active' => true]);
        $location = WarehouseLocation::factory()->create();
        $lot      = 'LOT-A-001';

        $po = PurchaseOrder::factory()->create([
            'status'     => PurchaseOrderStatus::Approved->value,
            'created_by' => $this->user->id,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => $item->id,
            'description'       => 'Resin lot',
            'quantity'          => '100.000',
            'unit'              => 'pcs',
            'unit_price'        => '10.00',
            'total'             => '1000.00',
            'quantity_received' => '0.000',
        ]);

        // Receive WITH a lot + expiry.
        $grn = $this->grnSvc->create($po, [[
            'purchase_order_item_id' => $poItem->id,
            'item_id'                => $item->id,
            'location_id'            => $location->id,
            'quantity_received'      => '100',
            'unit_cost'              => '10.00',
            'lot_number'             => $lot,
            'expiry_date'            => '2027-01-31',
        ]], [], $this->user);

        // The grn_item persisted the lot + expiry.
        $grnItem = $grn->items->first();
        $this->assertSame($lot, $grnItem->material_lot_number);
        $this->assertSame('2027-01-31', $grnItem->expiry_date->toDateString());

        // Accept → stock posts; movement carries the lot.
        $grn = $this->grnSvc->accept($grn->fresh(), $this->user);
        $this->assertSame(GrnStatus::Accepted, $grn->status);

        // Issue the same lot to a (nullable) work order.
        $this->misSvc->create([
            'issued_date' => now()->toDateString(),
            'items'       => [[
                'item_id'         => $item->id,
                'location_id'     => $location->id,
                'quantity_issued' => '40',
                'lot_number'      => $lot,
            ]],
        ], $this->user);

        // lotHistory returns BOTH the receipt and the issue, oldest first.
        $history = $this->movements->lotHistory($item->id, $lot);
        $this->assertCount(2, $history);
        $this->assertSame('grn_receipt', $history[0]->movement_type->value);
        $this->assertSame('material_issue', $history[1]->movement_type->value);
        $this->assertSame($lot, $history[0]->lot_number);
        $this->assertSame($lot, $history[1]->lot_number);
    }

    public function test_lot_capture_is_optional_and_null_safe(): void
    {
        $item     = Item::factory()->create(['is_active' => true]);
        $location = WarehouseLocation::factory()->create();

        $po = PurchaseOrder::factory()->create([
            'status'     => PurchaseOrderStatus::Approved->value,
            'created_by' => $this->user->id,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => $item->id,
            'description'       => 'No-lot line',
            'quantity'          => '50.000',
            'unit'              => 'pcs',
            'unit_price'        => '5.00',
            'total'             => '250.00',
            'quantity_received' => '0.000',
        ]);

        // No lot supplied — existing callers must keep working.
        $grn = $this->grnSvc->create($po, [[
            'purchase_order_item_id' => $poItem->id,
            'item_id'                => $item->id,
            'location_id'            => $location->id,
            'quantity_received'      => '50',
            'unit_cost'              => '5.00',
        ]], [], $this->user);

        $grn = $this->grnSvc->accept($grn->fresh(), $this->user);
        $this->assertSame(GrnStatus::Accepted, $grn->status);
        $this->assertNull($grn->items->first()->material_lot_number);
    }

    public function test_incoming_resin_qc_attributes_persist_on_grn_line(): void
    {
        $item     = Item::factory()->create(['is_active' => true]);
        $location = WarehouseLocation::factory()->create();

        $po = PurchaseOrder::factory()->create([
            'status'     => PurchaseOrderStatus::Approved->value,
            'created_by' => $this->user->id,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => $item->id,
            'description'       => 'Resin Type A',
            'quantity'          => '500.000',
            'unit'              => 'kg',
            'unit_price'        => '80.00',
            'total'             => '40000.00',
            'quantity_received' => '0.000',
        ]);

        // OGAMI-005 — receive resin WITH COA + moisture reading.
        $grn = $this->grnSvc->create($po, [[
            'purchase_order_item_id' => $poItem->id,
            'item_id'                => $item->id,
            'location_id'            => $location->id,
            'quantity_received'      => '500',
            'unit_cost'              => '80.00',
            'lot_number'             => 'RESIN-LOT-77',
            'moisture_percentage'    => '0.025',
            'coa_document_path'      => 'coa/resin-lot-77.pdf',
            'coa_verified'           => true,
        ]], [], $this->user);

        $line = $grn->items->first();
        $this->assertSame('0.025', (string) $line->moisture_percentage);
        $this->assertSame('coa/resin-lot-77.pdf', $line->coa_document_path);
        $this->assertTrue((bool) $line->coa_verified);
        $this->assertSame('RESIN-LOT-77', $line->material_lot_number);
    }
}
