<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

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
use App\Modules\Purchasing\Services\PurchaseOrderService;
use App\Modules\Quality\Models\Inspection;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use App\Common\Exceptions\BusinessRuleException;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PoShortCloseAfterRejectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    // Maker-checker: an incoming inspection counts only when a different user checks it.
    private User $checker;
    private GrnService $grnSvc;
    private PurchaseOrderService $poSvc;
    private ReturnRequestService $rmaSvc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(WorkflowSeeder::class);
        app(\App\Common\Services\SettingsService::class)->set('budgeting.enforcement_mode', 'off');

        $role = Role::firstOrCreate(['slug' => 'purchasing_officer'], ['name' => 'Purchasing Officer']);
        $this->user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $this->checker = User::factory()->create(['is_active' => true]);
        $this->grnSvc = app(GrnService::class);
        $this->poSvc = app(PurchaseOrderService::class);
        $this->rmaSvc = app(ReturnRequestService::class);
    }

    /**
     * Helper: Create a PO, GRN, and fail all incoming inspections so the GRN is fully rejected.
     */
    private function createFullyRejectedGrn(string $quantity = '100.000'): array
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
            'quantity' => $quantity,
            'unit' => 'pcs',
            'unit_price' => '10.00',
            'total' => bcmul($quantity, '10.00', 2),
            'quantity_received' => '0.000',
        ]);

        $grn = $this->grnSvc->create($po, [[
            'purchase_order_item_id' => $poItem->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_received' => $quantity,
            'unit_cost' => '10.00',
        ]], ['received_date' => now()->toDateString()], $this->user);

        // Fail all inspections and mark them as reviewed
        Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->update([
                'status' => 'failed',
                'reviewed_by' => $this->checker->id,
                'reviewed_at' => now(),
            ]);

        // Reject the GRN via incoming QC settlement
        $outcome = $this->grnSvc->settleIncomingQc($grn->fresh(), $this->user);
        $grn = $grn->fresh();

        return compact('po', 'poItem', 'grn', 'item', 'location');
    }

    public function test_fully_rejected_receipt_po_can_be_short_closed(): void
    {
        $ctx = $this->createFullyRejectedGrn('100.000');

        // After full rejection, GRN status should be rejected
        $this->assertSame(GrnStatus::Rejected, $ctx['grn']->status);

        // PO status should be back to Approved (or Sent if it was sent)
        $ctx['po']->refresh();
        $this->assertTrue(
            in_array($ctx['po']->status, [PurchaseOrderStatus::Approved, PurchaseOrderStatus::Sent], true),
            'After full GRN rejection, PO should be Approved or Sent'
        );

        // isShortClosable() should return true (has received goods but they were all rejected)
        $this->assertTrue($ctx['po']->isShortClosable());

        // shortClose() should succeed
        $closed = $this->poSvc->shortClose($ctx['po'], 'All goods rejected by QC', $this->user);

        $this->assertSame(PurchaseOrderStatus::Closed->value, $closed->status->value);
        $this->assertNotNull($closed->short_closed_at);
        $this->assertSame($this->user->id, $closed->short_closed_by);
    }

    public function test_po_without_receipts_cannot_be_short_closed(): void
    {
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'created_by' => $this->user->id,
        ]);

        // No GRNs at all (or only draft)
        $this->assertFalse($po->isShortClosable());

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Only a PO with received goods can be short-closed');

        $this->poSvc->shortClose($po, 'Cannot short-close', $this->user);
    }

    public function test_replacement_po_short_closes_original_when_it_owes_only_the_returned_qty(): void
    {
        $ctx = $this->createFullyRejectedGrn('100.000');
        $ctx['po']->refresh();

        // Create a supplier return for the rejected goods via system RMA
        $rma = ReturnRequest::create([
            'rma_number'         => 'RMA-TEST-' . substr(uniqid(), -5),
            'type'               => 'supplier_return',
            'status'             => ReturnRequestStatus::Draft,
            'vendor_id'          => $ctx['po']->vendor_id,
            'purchase_order_id'  => $ctx['po']->id,
            'goods_receipt_note_id' => $ctx['grn']->id,
            'return_date'        => now()->toDateString(),
            'created_by'         => $this->user->id,
        ]);

        // Add an item to the RMA
        // For rejected goods that never entered stock, set stock_movement_quantity
        // to avoid requiring a physical warehouse location for movement
        $rma->items()->create([
            'item_id'             => $ctx['item']->id,
            'quantity'            => '100.000',
            'disposition'         => 'return_to_supplier',
            'source_grn_item_id'  => $ctx['grn']->items->first()->id,
            'source_po_item_id'   => $ctx['poItem']->id,
            'reversal_already_applied' => true, // Flag that reversal was already applied
            'stock_movement_quantity' => '100.000', // No physical movement needed (never in stock)
        ]);

        // Move RMA to Inspected status (skip submission/approval for this test)
        $rma->forceFill(['status' => ReturnRequestStatus::Inspected->value])->save();

        // Dispose the RMA with createReplacementPo = true
        $this->rmaSvc->dispose($rma, [
            [
                'item_id'     => $rma->items->first()->hash_id,
                'disposition' => 'return_to_supplier',
            ],
        ], $this->user, true, $ctx['location']->id); // createReplacementPo = true, locationId

        // Original PO should now be Closed with short_closed_at set
        $ctx['po']->refresh();
        $this->assertSame(PurchaseOrderStatus::Closed->value, $ctx['po']->status->value);
        $this->assertNotNull($ctx['po']->short_closed_at);
        $this->assertStringContainsString('replacement PO', $ctx['po']->short_close_reason ?? '');

        // Replacement PO should exist
        $rma->refresh();
        $this->assertNotNull($rma->replacement_purchase_order_id, 'Replacement PO should be created');
        $replacement = PurchaseOrder::find($rma->replacement_purchase_order_id);
        $this->assertNotNull($replacement, 'Replacement PO should be found by ID');
        $this->assertSame(PurchaseOrderStatus::Draft->value, $replacement->status->value);
    }

    public function test_replacement_po_refused_when_original_still_owes_other_quantity(): void
    {
        // Create PO with 2 lines: qty 100 each
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'created_by' => $this->user->id,
        ]);

        $item1 = Item::factory()->create(['is_active' => true]);
        $item2 = Item::factory()->create(['is_active' => true]);
        $location = WarehouseLocation::factory()->create();

        $poItem1 = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item1->id,
            'description' => 'Item 1',
            'quantity' => '100.000',
            'unit' => 'pcs',
            'unit_price' => '10.00',
            'total' => '1000.00',
            'quantity_received' => '0.000',
        ]);

        $poItem2 = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item2->id,
            'description' => 'Item 2',
            'quantity' => '100.000',
            'unit' => 'pcs',
            'unit_price' => '10.00',
            'total' => '1000.00',
            'quantity_received' => '0.000',
        ]);

        // Receive only line 1
        $grn = $this->grnSvc->create($po, [[
            'purchase_order_item_id' => $poItem1->id,
            'item_id' => $item1->id,
            'location_id' => $location->id,
            'quantity_received' => '100.000',
            'unit_cost' => '10.00',
        ]], ['received_date' => now()->toDateString()], $this->user);

        // Fail all inspections so line 1 is rejected
        Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->update([
                'status' => 'failed',
                'reviewed_by' => $this->checker->id,
                'reviewed_at' => now(),
            ]);

        $outcome = $this->grnSvc->settleIncomingQc($grn->fresh(), $this->user);
        $grn = $grn->fresh();

        // Create supplier RMA for line 1 only
        $rma = ReturnRequest::create([
            'rma_number'         => 'RMA-TEST-' . substr(uniqid(), -5),
            'type'               => 'supplier_return',
            'status'             => ReturnRequestStatus::Draft,
            'vendor_id'          => $po->vendor_id,
            'purchase_order_id'  => $po->id,
            'goods_receipt_note_id' => $grn->id,
            'return_date'        => now()->toDateString(),
            'created_by'         => $this->user->id,
        ]);

        $rma->items()->create([
            'item_id'             => $item1->id,
            'quantity'            => '100.000',
            'disposition'         => 'return_to_supplier',
            'source_grn_item_id'  => $grn->items->first()->id,
            'source_po_item_id'   => $poItem1->id,
            'reversal_already_applied' => true,
            'stock_movement_quantity' => '100.000', // No physical movement needed (never in stock)
        ]);

        // Move RMA to Inspected status
        $rma->forceFill(['status' => ReturnRequestStatus::Inspected->value])->save();

        // Try to dispose with createReplacementPo = true
        // This should throw BusinessRuleException because line 2 is still undelivered
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('still expects other undelivered quantity');

        $this->rmaSvc->dispose($rma, [
            [
                'item_id'     => $rma->items->first()->hash_id,
                'disposition' => 'return_to_supplier',
            ],
        ], $this->user, true, $location->id);
    }

    public function test_return_does_not_reopen_a_short_closed_po(): void
    {
        $ctx = $this->createFullyRejectedGrn('100.000');
        $ctx['po']->refresh();

        // Manually short-close the PO
        $shortClosed = $this->poSvc->shortClose($ctx['po'], 'Test closure', $this->user);
        $this->assertSame(PurchaseOrderStatus::Closed->value, $shortClosed->status->value);

        // Now create a supplier return and dispose it
        $rma = ReturnRequest::create([
            'rma_number'         => 'RMA-TEST-' . substr(uniqid(), -5),
            'type'               => 'supplier_return',
            'status'             => ReturnRequestStatus::Draft,
            'vendor_id'          => $ctx['po']->vendor_id,
            'purchase_order_id'  => $ctx['po']->id,
            'goods_receipt_note_id' => $ctx['grn']->id,
            'return_date'        => now()->toDateString(),
            'created_by'         => $this->user->id,
        ]);

        $rma->items()->create([
            'item_id'             => $ctx['item']->id,
            'quantity'            => '50.000',
            'disposition'         => 'return_to_supplier',
            'source_grn_item_id'  => $ctx['grn']->items->first()->id,
            'source_po_item_id'   => $ctx['poItem']->id,
            'reversal_already_applied' => true,
            'stock_movement_quantity' => '50.000', // No physical movement needed (never in stock)
        ]);

        // Move RMA to Inspected status
        $rma->forceFill(['status' => ReturnRequestStatus::Inspected->value])->save();

        // Dispose WITHOUT creating replacement PO
        $this->rmaSvc->dispose($rma, [
            [
                'item_id'     => $rma->items->first()->hash_id,
                'disposition' => 'return_to_supplier',
            ],
        ], $this->user, false, $ctx['location']->id); // Do not create replacement

        // PO should still be Closed (not reopened)
        $ctx['po']->refresh();
        $this->assertSame(PurchaseOrderStatus::Closed->value, $ctx['po']->status->value);
        $this->assertNotNull($ctx['po']->short_closed_at);
    }
}
