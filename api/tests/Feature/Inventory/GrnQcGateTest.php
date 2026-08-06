<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Models\Inspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * F-06 — the incoming-QC gate must fail closed, never open.
 *
 * Inspection records are now created synchronously inside
 * GrnService::create(), so a GRN that reaches accept() has real QC rows to
 * evaluate. A GRN with QC-eligible lines (raw materials / plan items) and NO
 * inspection at all must be refused; only inspection-less GRNs whose lines are
 * not QC-eligible (finished goods without a plan) keep the pre-audit behavior.
 */
class GrnQcGateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private GrnService $grnSvc;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['slug' => 'warehouse_staff'], ['name' => 'Warehouse Staff']);
        $this->user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $this->grnSvc = app(GrnService::class);
    }

    private function makePoAndLine(Item $item): array
    {
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'created_by' => $this->user->id,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Material',
            'quantity' => '100.000',
            'unit' => 'pcs',
            'unit_price' => '10.00',
            'total' => '1000.00',
            'quantity_received' => '0.000',
        ]);

        return [$po, $poItem];
    }

    private function createGrnFor(Item $item): GoodsReceiptNote
    {
        [$po, $poItem] = $this->makePoAndLine($item);
        $location = WarehouseLocation::factory()->create();

        return $this->grnSvc->create($po, [[
            'purchase_order_item_id' => $poItem->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_received' => '10.000',
            'unit_cost' => '10.00',
        ]], ['received_date' => now()->toDateString()], $this->user);
    }

    public function test_create_synchronously_creates_incoming_inspections(): void
    {
        $item = Item::factory()->create(['is_active' => true]);

        $grn = $this->createGrnFor($item);

        $this->assertSame(1, Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->count(), 'an incoming inspection must exist the moment the GRN is created');
        $this->assertNotNull($grn->fresh()->qc_inspection_id, 'the GRN must be back-linked to its inspection');
    }

    public function test_accept_is_blocked_while_inspection_is_pending(): void
    {
        $item = Item::factory()->create(['is_active' => true]);
        $grn = $this->createGrnFor($item);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be accepted until every incoming inspection passes');

        $this->grnSvc->accept($grn, $this->user);
    }

    public function test_accept_succeeds_when_inspection_passes(): void
    {
        $item = Item::factory()->create(['is_active' => true]);
        $grn = $this->createGrnFor($item);

        Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->update(['status' => 'passed']);

        $accepted = $this->grnSvc->accept($grn->fresh(), $this->user);

        $this->assertSame(GrnStatus::Accepted, $accepted->status);
    }

    public function test_cancelled_inspection_does_not_block_acceptance(): void
    {
        $item = Item::factory()->create(['is_active' => true]);
        $grn = $this->createGrnFor($item);

        Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->update(['status' => 'cancelled']);

        $accepted = $this->grnSvc->accept($grn->fresh(), $this->user);

        $this->assertSame(GrnStatus::Accepted, $accepted->status);
    }

    public function test_grn_without_qc_eligible_lines_and_no_inspection_is_accepted(): void
    {
        // A finished-good item with no quality plan is not QC-eligible at the
        // incoming gate; such a GRN keeps the legacy fail-open behavior.
        $item = Item::factory()->create([
            'item_type' => 'finished_good',
            'is_active' => true,
        ]);
        [$po, $poItem] = $this->makePoAndLine($item);
        $location = WarehouseLocation::factory()->create();

        $grn = GoodsReceiptNote::create([
            'grn_number' => 'GRN-'.substr(uniqid(), -10),
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'received_date' => now()->toDateString(),
            'received_by' => $this->user->id,
            'status' => GrnStatus::PendingQc,
        ]);
        \App\Modules\Inventory\Models\GrnItem::create([
            'goods_receipt_note_id' => $grn->id,
            'purchase_order_item_id' => $poItem->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_received' => '10.000',
            'quantity_accepted' => 0,
            'unit_cost' => '10.00',
        ]);

        $accepted = $this->grnSvc->accept($grn->fresh(), $this->user);

        $this->assertSame(GrnStatus::Accepted, $accepted->status);
    }
}
