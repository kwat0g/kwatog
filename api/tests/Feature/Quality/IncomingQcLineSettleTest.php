<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Common\Services\SettingsService;
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

/**
 * Incoming QC Line Settlement Tests
 *
 * When a GRN has multiple lines and receives per-line incoming inspections,
 * the settlement logic (GrnService::settleIncomingQc) decides the GRN status
 * based on ALL line inspections:
 *
 * - No failed lines → accept() entire GRN.
 * - All lines failed → reject() entire GRN.
 * - Mixed (some passed, some failed) → partialAccept() good lines,
 *   then rejectRemainder() failed lines.
 * - Any inspection still draft/in-progress → awaiting_sibling_qc, do nothing.
 *
 * These tests verify the logic and fix the old behavior where the first
 * failure rejected the whole GRN, losing good lines.
 */
class IncomingQcLineSettleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    // Maker-checker: an incoming inspection counts only when a different user checks it.
    private User $checker;
    private GrnService $grnSvc;

    protected function setUp(): void
    {
        parent::setUp();

        // Legacy tests: disable MRB review to test the old auto-reject behavior
        app(SettingsService::class)->set('quality.incoming_failure.mrb_review', false);

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

    /**
     * Create a 2-line GRN with incoming inspections per line.
     */
    private function createMultiLineGrn(string $line1Qty = '100.000', string $line2Qty = '50.000'): GoodsReceiptNote
    {
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
            'description' => 'Material A',
            'quantity' => '100.000',
            'unit' => 'pcs',
            'unit_price' => '10.00',
            'total' => '1000.00',
            'quantity_received' => '0.000',
        ]);

        $poItem2 = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item2->id,
            'description' => 'Material B',
            'quantity' => '50.000',
            'unit' => 'pcs',
            'unit_price' => '20.00',
            'total' => '1000.00',
            'quantity_received' => '0.000',
        ]);

        $grn = $this->grnSvc->create($po, [
            [
                'purchase_order_item_id' => $poItem1->id,
                'item_id' => $item1->id,
                'location_id' => $location->id,
                'quantity_received' => $line1Qty,
                'unit_cost' => '10.00',
            ],
            [
                'purchase_order_item_id' => $poItem2->id,
                'item_id' => $item2->id,
                'location_id' => $location->id,
                'quantity_received' => $line2Qty,
                'unit_cost' => '20.00',
            ],
        ], ['received_date' => now()->toDateString()], $this->user);

        return $grn;
    }

    public function test_mixed_pass_fail_line_a_fails_line_b_passes(): void
    {
        $grn = $this->createMultiLineGrn('100.000', '50.000');
        $lineAId = $grn->items->get(0)->id;
        $lineBId = $grn->items->get(1)->id;
        $poItemAId = $grn->purchaseOrder->items->get(0)->id;

        $inspections = Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->get()
            ->keyBy('grn_item_id');

        // Line A (resin) fails
        $inspections[$lineAId]->forceFill(['status' => 'failed', 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()])->save();
        // Line B (packaging) passes
        $inspections[$lineBId]->forceFill(['status' => 'passed', 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()])->save();

        // Settle
        $outcome = $this->grnSvc->settleIncomingQc($grn->fresh(), $this->user);

        $this->assertSame('grn_partially_accepted', $outcome);

        $grn = $grn->fresh()->load('items', 'purchaseOrder.items');
        $this->assertSame(GrnStatus::PartialAccepted, $grn->status);
        $this->assertNotNull($grn->remainder_rejected_at, 'Remainder should be marked as rejected');

        // Line A should have 0 accepted, Line B should have full received quantity accepted
        $lineA = $grn->items->firstWhere('id', $lineAId);
        $lineB = $grn->items->firstWhere('id', $lineBId);
        $this->assertSame('0.000', (string) $lineA->quantity_accepted, 'Failed line A should have 0 accepted');
        $this->assertSame('50.000', (string) $lineB->quantity_accepted, 'Passed line B should be fully accepted');

        // PO line A received should be reduced by the remainder
        $poItemA = $grn->purchaseOrder->items->firstWhere('id', $poItemAId);
        $this->assertSame('0.00', (string) $poItemA->quantity_received, 'PO line A received should drop to 0');

        // Exactly one RMA with source_key grn-rejection:{grn id}
        $rma = ReturnRequest::where('source_key', 'grn-rejection:' . $grn->id)->first();
        $this->assertNotNull($rma, 'An RMA should be opened for the rejected remainder');
        $this->assertCount(1, $rma->items, 'RMA should contain only the failed line A');
        $this->assertSame('100.000', (string) $rma->items->first()->quantity, 'RMA should be for line A full quantity');
    }

    public function test_mixed_pass_fail_line_b_fails_line_a_passes(): void
    {
        $grn = $this->createMultiLineGrn('100.000', '50.000');
        $lineAId = $grn->items->get(0)->id;
        $lineBId = $grn->items->get(1)->id;

        $inspections = Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->get()
            ->keyBy('grn_item_id');

        // Line A passes
        $inspections[$lineAId]->forceFill(['status' => 'passed', 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()])->save();
        // Line B fails
        $inspections[$lineBId]->forceFill(['status' => 'failed', 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()])->save();

        // Settle
        $outcome = $this->grnSvc->settleIncomingQc($grn->fresh(), $this->user);

        $this->assertSame('grn_partially_accepted', $outcome);

        $grn = $grn->fresh()->load('items');
        $this->assertSame(GrnStatus::PartialAccepted, $grn->status);

        // Line A should be fully accepted, Line B should have 0 accepted
        $lineA = $grn->items->firstWhere('id', $lineAId);
        $lineB = $grn->items->firstWhere('id', $lineBId);
        $this->assertSame('100.000', (string) $lineA->quantity_accepted, 'Passed line A should be fully accepted');
        $this->assertSame('0.000', (string) $lineB->quantity_accepted, 'Failed line B should have 0 accepted');

        // RMA should contain only the failed line B
        $rma = ReturnRequest::where('source_key', 'grn-rejection:' . $grn->id)->first();
        $this->assertNotNull($rma);
        $this->assertCount(1, $rma->items);
        $this->assertSame('50.000', (string) $rma->items->first()->quantity, 'RMA should be for line B full quantity');
    }

    public function test_all_lines_fail_rejects_grn(): void
    {
        $grn = $this->createMultiLineGrn('100.000', '50.000');
        $lineAId = $grn->items->get(0)->id;
        $lineBId = $grn->items->get(1)->id;

        $inspections = Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->get()
            ->keyBy('grn_item_id');

        // Both lines fail
        $inspections[$lineAId]->forceFill(['status' => 'failed', 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()])->save();
        $inspections[$lineBId]->forceFill(['status' => 'failed', 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()])->save();

        $outcome = $this->grnSvc->settleIncomingQc($grn->fresh(), $this->user);

        $this->assertSame('grn_rejected', $outcome);

        $grn = $grn->fresh()->load('items');
        $this->assertSame(GrnStatus::Rejected, $grn->status);

        // RMA should be created for the entire GRN (both lines)
        $rma = ReturnRequest::where('source_key', 'grn-rejection:' . $grn->id)->first();
        $this->assertNotNull($rma);
        $this->assertCount(2, $rma->items, 'RMA should contain both lines');
    }

    public function test_all_lines_pass_accepts_grn(): void
    {
        $grn = $this->createMultiLineGrn('100.000', '50.000');
        $lineAId = $grn->items->get(0)->id;
        $lineBId = $grn->items->get(1)->id;

        $inspections = Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->get()
            ->keyBy('grn_item_id');

        // Both lines pass
        $inspections[$lineAId]->forceFill(['status' => 'passed', 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()])->save();
        $inspections[$lineBId]->forceFill(['status' => 'passed', 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()])->save();

        $outcome = $this->grnSvc->settleIncomingQc($grn->fresh(), $this->user);

        $this->assertSame('grn_accepted', $outcome);

        $grn = $grn->fresh()->load('items');
        $this->assertSame(GrnStatus::Accepted, $grn->status);

        // Both lines should be fully accepted
        $this->assertSame('100.000', (string) $grn->items->firstWhere('id', $lineAId)->quantity_accepted);
        $this->assertSame('50.000', (string) $grn->items->firstWhere('id', $lineBId)->quantity_accepted);

        // No RMA should be created
        $rma = ReturnRequest::where('source_key', 'grn-rejection:' . $grn->id)->first();
        $this->assertNull($rma, 'No RMA should be created when all lines pass');
    }

    public function test_line_a_fails_line_b_in_progress_awaits_qc(): void
    {
        $grn = $this->createMultiLineGrn('100.000', '50.000');
        $lineAId = $grn->items->get(0)->id;
        $lineBId = $grn->items->get(1)->id;

        $inspections = Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->get()
            ->keyBy('grn_item_id');

        // Line A fails
        $inspections[$lineAId]->forceFill(['status' => 'failed', 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()])->save();
        // Line B is still in-progress (status = 'in_progress')
        $inspections[$lineBId]->forceFill(['status' => 'in_progress'])->save();

        $outcome = $this->grnSvc->settleIncomingQc($grn->fresh(), $this->user);

        $this->assertSame('awaiting_sibling_qc', $outcome);

        $grn = $grn->fresh()->load('items');
        // GRN should still be pending_qc
        $this->assertSame(GrnStatus::PendingQc, $grn->status);
        // Nothing should have been accepted or rejected
        $this->assertSame('0.000', (string) $grn->items->firstWhere('id', $lineAId)->quantity_accepted);
        $this->assertSame('0.000', (string) $grn->items->firstWhere('id', $lineBId)->quantity_accepted);
    }

    public function test_cancelled_inspection_counts_as_not_failed(): void
    {
        $grn = $this->createMultiLineGrn('100.000', '50.000');
        $lineAId = $grn->items->get(0)->id;
        $lineBId = $grn->items->get(1)->id;

        $inspections = Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->get()
            ->keyBy('grn_item_id');

        // Line A is cancelled (logistics rejection)
        $inspections[$lineAId]->forceFill(['status' => 'cancelled'])->save();
        // Line B passes
        $inspections[$lineBId]->forceFill(['status' => 'passed', 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()])->save();

        $outcome = $this->grnSvc->settleIncomingQc($grn->fresh(), $this->user);

        // Should accept because no lines FAILED (cancelled doesn't count as failed)
        $this->assertSame('grn_accepted', $outcome);

        $grn = $grn->fresh()->load('items');
        $this->assertSame(GrnStatus::Accepted, $grn->status);
    }

    public function test_partial_accept_with_failed_line_throws(): void
    {
        $grn = $this->createMultiLineGrn('100.000', '50.000');
        $lineAId = $grn->items->get(0)->id;
        $lineBId = $grn->items->get(1)->id;

        $inspections = Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->get()
            ->keyBy('grn_item_id');

        // Line A fails
        $inspections[$lineAId]->forceFill(['status' => 'failed', 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()])->save();
        // Line B passes
        $inspections[$lineBId]->forceFill(['status' => 'passed', 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()])->save();

        // Try to manually partialAccept accepting >0 on the failed line A
        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $this->expectExceptionMessage('failed incoming QC');

        $this->grnSvc->partialAccept($grn, [
            $lineAId => '50.000', // Trying to accept 50 on a failed line
            $lineBId => '50.000',
        ], $this->user);
    }

    public function test_partial_accept_only_passed_line_succeeds(): void
    {
        $grn = $this->createMultiLineGrn('100.000', '50.000');
        $lineAId = $grn->items->get(0)->id;
        $lineBId = $grn->items->get(1)->id;

        $inspections = Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->get()
            ->keyBy('grn_item_id');

        // Line A fails
        $inspections[$lineAId]->forceFill(['status' => 'failed', 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()])->save();
        // Line B passes
        $inspections[$lineBId]->forceFill(['status' => 'passed', 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()])->save();

        // Manually partialAccept accepting only line B (passed line) and 0 on line A (failed)
        $result = $this->grnSvc->partialAccept($grn, [
            $lineAId => '0.000',     // Accept 0 on failed line (allowed)
            $lineBId => '50.000',
        ], $this->user);

        $this->assertSame(GrnStatus::PartialAccepted, $result->status);
        $result = $result->load('items');
        $this->assertSame('0.000', (string) $result->items->firstWhere('id', $lineAId)->quantity_accepted);
        $this->assertSame('50.000', (string) $result->items->firstWhere('id', $lineBId)->quantity_accepted);
    }

    public function test_partial_accept_checks_every_failed_line_not_just_the_first(): void
    {
        $grn = $this->createMultiLineGrn('100.000', '50.000');
        $lineAId = $grn->items->get(0)->id;
        $lineBId = $grn->items->get(1)->id;

        Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->get()
            ->each(fn ($insp) => $insp->forceFill(['status' => 'failed', 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()])->save());

        // Zeroing the first failed line must not unlock the second one.
        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $this->expectExceptionMessage("Line {$lineBId} failed incoming QC");

        $this->grnSvc->partialAccept($grn, [
            $lineAId => '0.000',
            $lineBId => '50.000',
        ], $this->user);
    }

    public function test_partial_accept_waits_for_unfinished_line_inspection(): void
    {
        $grn = $this->createMultiLineGrn('100.000', '50.000');
        $lineAId = $grn->items->get(0)->id;
        $lineBId = $grn->items->get(1)->id;

        $inspections = Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->get()
            ->keyBy('grn_item_id');
        $inspections[$lineAId]->forceFill(['status' => 'in_progress'])->save();
        $inspections[$lineBId]->forceFill(['status' => 'passed', 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()])->save();

        // An unfinished verdict is not a failure; accepting around it would
        // close the GRN before line A's result lands.
        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $this->expectExceptionMessage('until every incoming inspection is complete');

        $this->grnSvc->partialAccept($grn, [
            $lineAId => '0.000',
            $lineBId => '50.000',
        ], $this->user);
    }

    public function test_already_terminal_grn_returns_already_terminal(): void
    {
        $grn = $this->createMultiLineGrn('100.000', '50.000');

        $inspections = Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->get();

        $inspections->each(fn ($insp) => $insp->forceFill(['status' => 'passed', 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()])->save());

        // First settle should accept
        $outcome1 = $this->grnSvc->settleIncomingQc($grn->fresh(), $this->user);
        $this->assertSame('grn_accepted', $outcome1);

        // Second settle on the same GRN (now Accepted) should return already_terminal
        $outcome2 = $this->grnSvc->settleIncomingQc($grn->fresh(), $this->user);
        $this->assertSame('grn_already_terminal', $outcome2);
    }
}
