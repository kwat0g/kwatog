<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\Permission;
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
use App\Modules\ReturnManagement\Models\ReturnRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrnRejectRemainderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    // Maker-checker: an incoming inspection counts only when a different user checks it.
    private User $checker;
    private GrnService $grnSvc;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::firstOrCreate(['slug' => 'warehouse_staff'], ['name' => 'Warehouse Staff']);
        $permission = Permission::firstOrCreate(
            ['slug' => 'inventory.grn.create'],
            ['name' => 'Create GRN', 'module' => 'inventory'],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $this->user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $this->checker = User::factory()->create(['is_active' => true]);
        $this->grnSvc = app(GrnService::class);
    }

    private function createPartiallyAcceptedGrn(string $receivedQty = '100.000', string $acceptedQty = '60.000'): GoodsReceiptNote
    {
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'created_by' => $this->user->id,
        ]);

        $item = Item::factory()->create(['is_active' => true]);
        $location = WarehouseLocation::factory()->create();

        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Test Material',
            'quantity' => '100.000',
            'unit' => 'pcs',
            'unit_price' => '10.00',
            'total' => '1000.00',
            'quantity_received' => '0.000',
        ]);

        $grn = $this->grnSvc->create($po, [[
            'purchase_order_item_id' => $poItem->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_received' => $receivedQty,
            'unit_cost' => '10.00',
        ]], ['received_date' => now()->toDateString()], $this->user);

        // Pass inspections so partial accept can proceed
        Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->update(['status' => 'passed', 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()]);

        // Partial accept
        $grn = $this->grnSvc->partialAccept(
            $grn,
            [$grn->items->first()->id => $acceptedQty],
            $this->user,
        );

        return $grn;
    }

    public function test_reject_remainder_reduces_po_line_received_quantity(): void
    {
        $grn = $this->createPartiallyAcceptedGrn('100.000', '60.000');
        $poItem = $grn->purchaseOrder->items->first();

        $this->assertSame('100.00', (string) $poItem->quantity_received);
        $this->assertSame('60.000', (string) $poItem->quantity_accepted);

        $result = $this->grnSvc->rejectRemainder($grn, 'Supplier rejection due to material defect', $this->user);

        $this->assertSame(GrnStatus::PartialAccepted, $result->status);
        $this->assertNotNull($result->remainder_rejected_at);
        $this->assertSame($this->user->id, $result->remainder_rejected_by);
        $this->assertSame('Supplier rejection due to material defect', $result->remainder_rejected_reason);

        // PO line quantity_received should drop by 40
        $poItem->refresh();
        $this->assertSame('60.00', (string) $poItem->quantity_received, 'PO item received qty should drop by remainder (40)');
        $this->assertSame('60.000', (string) $poItem->quantity_accepted, 'PO item accepted qty should stay same');
    }

    public function test_reject_remainder_opens_supplier_return(): void
    {
        $grn = $this->createPartiallyAcceptedGrn('100.000', '60.000');

        $this->grnSvc->rejectRemainder($grn, 'Remainder rejected', $this->user);

        // Check that a supplier return was created with source_key grn-rejection:{id}
        $return = ReturnRequest::where('source_key', 'grn-rejection:' . $grn->id)->first();
        $this->assertNotNull($return, 'A supplier return should be opened for the rejected remainder');

        // The return should be for 40 units (remainder)
        $returnLine = $return->items->first();
        $this->assertSame('40.000', (string) $returnLine->quantity);
    }

    public function test_reject_remainder_twice_throws(): void
    {
        $grn = $this->createPartiallyAcceptedGrn('100.000', '60.000');

        $this->grnSvc->rejectRemainder($grn, 'First rejection', $this->user);

        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $this->expectExceptionMessage('already rejected');

        $this->grnSvc->rejectRemainder($grn->fresh(), 'Second rejection', $this->user);
    }

    public function test_partial_accept_after_remainder_rejection_throws(): void
    {
        $grn = $this->createPartiallyAcceptedGrn('100.000', '60.000');
        $grn = $this->grnSvc->rejectRemainder($grn, 'Remainder rejected', $this->user);

        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $this->expectExceptionMessage('rejected');

        $this->grnSvc->partialAccept($grn->fresh(), [$grn->items->first()->id => '70.000'], $this->user);
    }

    public function test_reject_remainder_on_pending_qc_grn_throws(): void
    {
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'created_by' => $this->user->id,
        ]);

        $item = Item::factory()->create(['is_active' => true]);
        $location = WarehouseLocation::factory()->create();

        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Test',
            'quantity' => '100.000',
            'unit' => 'pcs',
            'unit_price' => '10.00',
            'total' => '1000.00',
            'quantity_received' => '0.000',
        ]);

        $grn = $this->grnSvc->create($po, [[
            'purchase_order_item_id' => $poItem->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_received' => '100.000',
            'unit_cost' => '10.00',
        ]], ['received_date' => now()->toDateString()], $this->user);

        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $this->expectExceptionMessage('partial_accepted');

        $this->grnSvc->rejectRemainder($grn, 'Cannot reject on pending_qc', $this->user);
    }

    public function test_reject_remainder_on_fully_accepted_grn_throws(): void
    {
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'created_by' => $this->user->id,
        ]);

        $item = Item::factory()->create(['is_active' => true]);
        $location = WarehouseLocation::factory()->create();

        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Test',
            'quantity' => '100.000',
            'unit' => 'pcs',
            'unit_price' => '10.00',
            'total' => '1000.00',
            'quantity_received' => '0.000',
        ]);

        $grn = $this->grnSvc->create($po, [[
            'purchase_order_item_id' => $poItem->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_received' => '100.000',
            'unit_cost' => '10.00',
        ]], ['received_date' => now()->toDateString()], $this->user);

        // Pass inspections and fully accept
        Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->update(['status' => 'passed', 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()]);

        $this->grnSvc->accept($grn, $this->user);

        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $this->expectExceptionMessage('partial_accepted');

        $this->grnSvc->rejectRemainder($grn->fresh(), 'Cannot reject on fully accepted', $this->user);
    }

    public function test_http_reject_remainder_with_short_reason_fails(): void
    {
        $grn = $this->createPartiallyAcceptedGrn('100.000', '60.000');

        $this->actingAs($this->user)
            ->patchJson("/api/v1/inventory/grn/{$grn->hash_id}/reject-remainder", ['reason' => 'Short'])
            ->assertStatus(422);
    }

    public function test_http_reject_remainder_without_permission_fails(): void
    {
        $grn = $this->createPartiallyAcceptedGrn('100.000', '60.000');

        $userNoPermission = User::factory()->create(['is_active' => true]);

        $this->actingAs($userNoPermission)
            ->patchJson("/api/v1/inventory/grn/{$grn->hash_id}/reject-remainder", ['reason' => 'Valid reason that is long enough'])
            ->assertStatus(403);
    }

    public function test_http_reject_remainder_success(): void
    {
        $grn = $this->createPartiallyAcceptedGrn('100.000', '60.000');

        $response = $this->actingAs($this->user)
            ->patchJson("/api/v1/inventory/grn/{$grn->hash_id}/reject-remainder", [
                'reason' => 'Supplier shipment damaged in transit and must be returned',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', GrnStatus::PartialAccepted->value);

        // Just check that remainder_rejected_at is set (not null)
        $this->assertNotNull($response['data']['remainder_rejected_at']);
    }

    public function test_reject_remainder_with_no_remainder_throws(): void
    {
        // Create a GRN with two lines
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'created_by' => $this->user->id,
        ]);

        $item = Item::factory()->create(['is_active' => true]);
        $location = WarehouseLocation::factory()->create();

        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Test',
            'quantity' => '100.000',
            'unit' => 'pcs',
            'unit_price' => '10.00',
            'total' => '1000.00',
            'quantity_received' => '0.000',
        ]);

        $grn = $this->grnSvc->create($po, [[
            'purchase_order_item_id' => $poItem->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_received' => '100.000',
            'unit_cost' => '10.00',
        ]], ['received_date' => now()->toDateString()], $this->user);

        Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->update(['status' => 'passed', 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()]);

        // Fully accept (no remainder) - this will make the status Accepted, not PartialAccepted
        $grn = $this->grnSvc->partialAccept(
            $grn,
            [$grn->items->first()->id => '100.000'],
            $this->user,
        );

        // Verify status is now Accepted, not PartialAccepted
        $this->assertSame(GrnStatus::Accepted->value, (string) $grn->status->value);

        // This should fail because the GRN is Accepted, not PartialAccepted
        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $this->expectExceptionMessage('partial_accepted');

        $this->grnSvc->rejectRemainder($grn->fresh(), 'Cannot reject when fully accepted', $this->user);
    }
}
