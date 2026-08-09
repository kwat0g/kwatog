<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\BillItem;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The supplier-return leg of Chain 2, driven end to end over HTTP:
 * draft → submit → approve → receive → inspect → dispose → complete.
 *
 * DispositionTest covers dispose() in isolation via the service. Nothing
 * covered the whole walk through the API, which is where the workflow got
 * stuck: a missing document sequence, undecoded hash IDs, and an approval
 * chain that could never be satisfied.
 */
class SupplierReturnLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(WorkflowSeeder::class);
        app(\App\Common\Services\SettingsService::class)->set('budgeting.enforcement_mode', 'off');
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
        ]);
    }

    /**
     * A vendor with 100kg of resin received, accepted, stocked and billed.
     *
     * @return array{vendor: Vendor, item: Item, location: WarehouseLocation, po: PurchaseOrder, poItem: PurchaseOrderItem, grnItem: GrnItem, bill: Bill, billItem: BillItem}
     */
    private function receivedShipment(User $by): array
    {
        $vendor   = Vendor::factory()->create(['created_by' => null]);
        $item     = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();
        $expense  = Account::query()->where('type', 'expense')->firstOrFail();

        $po = PurchaseOrder::factory()->create([
            'vendor_id'    => $vendor->id,
            'created_by'   => $by->id,
            'subtotal'     => '1000.00',
            'vat_amount'   => '120.00',
            'total_amount' => '1120.00',
            'is_vatable'   => true,
        ]);
        $po->forceFill(['status' => PurchaseOrderStatus::Received])->save();

        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => $item->id,
            'description'       => 'Resin for supplier-return lifecycle',
            'quantity'          => '100.00',
            'unit'              => 'kg',
            'unit_price'        => '10.00',
            'total'             => '1000.00',
            'quantity_received' => '100.00',
        ]);

        $grn = GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $po->id,
            'vendor_id'         => $vendor->id,
            'received_by'       => $by->id,
            'status'            => 'accepted',
        ]);
        $grnItem = GrnItem::create([
            'goods_receipt_note_id'  => $grn->id,
            'purchase_order_item_id' => $poItem->id,
            'item_id'                => $item->id,
            'location_id'            => $location->id,
            'quantity_received'      => '100.000',
            'quantity_accepted'      => '100.000',
            'unit_cost'              => '10.0000',
        ]);

        // Put the goods physically on the shelf so the return can take them off.
        app(StockMovementService::class)->move(new StockMovementInput(
            type: StockMovementType::AdjustmentIn,
            itemId: $item->id,
            toLocationId: $location->id,
            quantity: '100',
            unitCost: '10.00',
            referenceType: 'opening',
            createdBy: $by->id,
        ));

        $bill = Bill::create([
            'bill_number'       => 'BILL-SL-' . substr(uniqid(), -5),
            'vendor_id'         => $vendor->id,
            'purchase_order_id' => $po->id,
            'status'            => 'unpaid',
            'subtotal'          => '1000.00',
            'vat_amount'        => '120.00',
            'total_amount'      => '1120.00',
            'amount_paid'       => '0.00',
            'balance'           => '1120.00',
            'date'              => now()->toDateString(),
            'due_date'          => now()->addDays(30)->toDateString(),
            'is_vatable'        => true,
            'created_by'        => $by->id,
        ]);
        $billItem = BillItem::create([
            'bill_id'            => $bill->id,
            'expense_account_id' => $expense->id,
            'item_id'            => $item->id,
            'description'        => 'Received resin',
            'quantity'           => '100.00',
            'unit'               => 'kg',
            'unit_price'         => '10.00',
            'total'              => '1000.00',
        ]);

        return compact('vendor', 'item', 'location', 'po', 'poItem', 'grnItem', 'bill', 'billItem');
    }

    public function test_supplier_return_walks_the_full_workflow_over_http(): void
    {
        $admin = $this->admin();
        $ctx   = $this->receivedShipment($admin);

        // ── Create ────────────────────────────────────────────────────────
        $created = $this->actingAs($admin)
            ->postJson('/api/v1/return-management/return-requests', [
                'type'              => 'supplier_return',
                'vendor_id'         => $ctx['vendor']->hash_id,
                'purchase_order_id' => $ctx['po']->hash_id,
                'bill_id'           => $ctx['bill']->hash_id,
                'reason_code'       => 'quality_issue',
                'return_date'       => now()->toDateString(),
                'items'             => [[
                    'item_id'             => $ctx['item']->hash_id,
                    'quantity'            => 20,
                    'unit_price'          => 10,
                    'source_po_item_id'   => $ctx['poItem']->hash_id,
                    'source_grn_item_id'  => $ctx['grnItem']->hash_id,
                    'source_bill_item_id' => $ctx['billItem']->hash_id,
                ]],
            ])
            ->assertCreated()
            ->json('data');

        $rmaId = $created['id'];
        $this->assertStringStartsWith('RMA-', $created['rma_number']);

        // ── Submit → approve ──────────────────────────────────────────────
        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rmaId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_approval');

        // Maker-checker: the submitter cannot approve their own RMA. The seeded
        // chain routes to department_head then production_manager.
        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rmaId}/approve")
            ->assertStatus(422);

        $this->actingAs($this->userWithRole('department_head'))
            ->postJson("/api/v1/return-management/return-requests/{$rmaId}/approve", ['remarks' => 'Confirmed off-spec.'])
            ->assertOk();
        $this->actingAs($this->userWithRole('production_manager'))
            ->postJson("/api/v1/return-management/return-requests/{$rmaId}/approve")
            ->assertOk();

        $rma = ReturnRequest::query()->firstOrFail();
        $this->assertSame(ReturnRequestStatus::Approved, $rma->status, 'The chain must reach approved.');

        // ── Receive: only 18 of the 20 claimed actually shipped back ───────
        $line = $rma->items()->firstOrFail();
        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rmaId}/receive", [
                'received_quantities' => [$line->hash_id => 18],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'received');

        $this->assertSame('18.000', $line->fresh()->returned_quantity);

        // ── Inspect ───────────────────────────────────────────────────────
        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rmaId}/inspect", [
                'internal_notes' => 'Moisture out of spec on 18kg.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'inspected');

        // ── Dispose: back to the supplier, with a replacement PO ───────────
        // 2026-08-08 — the ReturnToVendor movement happens HERE (goods leave
        // stock the moment the disposition is recorded), not at complete().
        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rmaId}/dispose", [
                'dispositions' => [[
                    'item_id'     => $line->hash_id,
                    'disposition' => 'return_to_supplier',
                    'notes'       => 'Moisture out of spec.',
                ]],
                'create_replacement_po' => true,
                'location_id'   => $ctx['location']->hash_id,
            ])
            ->assertOk();

        $rma->refresh();
        $this->assertSame('disposed', $rma->disposition_status);

        // The receipt is reversed by the quantity actually returned, not claimed.
        $this->assertSame('82.000', $ctx['grnItem']->fresh()->quantity_received);
        $this->assertSame('82.000', $ctx['grnItem']->fresh()->quantity_accepted);
        $this->assertSame('82.00', $ctx['poItem']->fresh()->quantity_received);

        // A supplier credit note is raised and applied against the open bill.
        $this->assertNotNull($rma->credit_note_id, 'A supplier return must raise a credit note.');
        $this->assertSame('180.00', $rma->creditNote->subtotal, '18kg × ₱10.00');
        $this->assertTrue(
            (float) $ctx['bill']->fresh()->balance < 1120.00,
            'The credit note must be applied against the open bill.',
        );

        // And the replacement PO the checkbox asked for.
        $this->assertNotNull($rma->replacement_purchase_order_id);

        // The goods left the shelf at dispose time — no waiting for complete.
        $movement = StockMovement::query()
            ->where('reference_type', 'return_request')
            ->where('reference_id', $rma->id)
            ->firstOrFail();
        $this->assertSame(StockMovementType::ReturnToVendor, $movement->movement_type);
        $this->assertSame('18.000', (string) $movement->quantity, 'Only the returned quantity leaves stock.');
        $this->assertSame('82.000', (string) \App\Modules\Inventory\Models\StockLevel::where('item_id', $ctx['item']->id)
            ->where('location_id', $ctx['location']->id)->firstOrFail()->quantity, '100 on shelf − 18 shipped back');

        // ── Complete: closes the RMA; nothing left to move, no location asked ─
        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rmaId}/complete", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame(
            1,
            StockMovement::query()
                ->where('reference_type', 'return_request')
                ->where('reference_id', $rma->id)
                ->count(),
            'Complete must not create a second movement for already-shipped lines.',
        );
    }

    public function test_supplier_return_without_source_lineage_is_refused_cleanly(): void
    {
        $admin = $this->admin();
        $ctx   = $this->receivedShipment($admin);

        // No source GRN / PO lines — the service cannot reverse the receipt.
        $created = $this->actingAs($admin)
            ->postJson('/api/v1/return-management/return-requests', [
                'type'              => 'supplier_return',
                'vendor_id'         => $ctx['vendor']->hash_id,
                'purchase_order_id' => $ctx['po']->hash_id,
                'reason_code'       => 'quality_issue',
                'return_date'       => now()->toDateString(),
                'items'             => [[
                    'item_id'    => $ctx['item']->hash_id,
                    'quantity'   => 5,
                    'unit_price' => 10,
                ]],
            ])
            ->assertCreated()
            ->json('data');

        $rma = ReturnRequest::query()->firstOrFail();
        $rma->forceFill(['status' => ReturnRequestStatus::Inspected->value])->save();

        // A location is included so the request passes the location rule and
        // actually reaches the service — otherwise the 422 would come from the
        // missing location, not from the lineage guard this test is about.
        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$created['id']}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items()->firstOrFail()->hash_id,
                    'disposition' => 'return_to_supplier',
                ]],
                'location_id'  => $ctx['location']->hash_id,
            ])
            ->assertStatus(422);

        // The failed disposition must not leave a half-applied state behind.
        $this->assertNull($rma->fresh()->disposition_status);
        $this->assertSame('100.000', $ctx['grnItem']->fresh()->quantity_received);
    }
}
