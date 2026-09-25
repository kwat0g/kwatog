<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\BillItem;
use App\Modules\Accounting\Models\CreditNote;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemUomConversion;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Models\ReturnCase;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
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
     * A posted GL entry to hang a billed document off, mirroring the
     * `CreditNoteTest::postedJournalEntry()` fixture. The amounts are token
     * values — only the posted status is what `CreditNoteService::apply()`
     * inspects on the target bill.
     */
    private function postedJournalEntry(User $by): JournalEntry
    {
        return JournalEntry::create([
            'entry_number' => 'JE-RMA-' . substr(uniqid(), -8),
            'date'         => now()->toDateString(),
            'description'  => 'Posted target bill fixture',
            'total_debit'  => '1.00',
            'total_credit' => '1.00',
            'status'       => JournalEntryStatus::Posted,
            'posted_at'    => now(),
            'posted_by'    => $by->id,
        ]);
    }

    /**
     * A vendor with 100kg of resin received, accepted, stocked and billed.
     *
     * @return array{vendor: Vendor, item: Item, location: WarehouseLocation, po: PurchaseOrder, poItem: PurchaseOrderItem, grnItem: GrnItem, bill: Bill, billItem: BillItem}
     */
    private function receivedShipment(User $by, bool $isVatable = true): array
    {
        $vendor   = Vendor::factory()->create(['created_by' => null]);
        $item     = Item::factory()->create(['unit_of_measure' => 'kg']);
        $location = WarehouseLocation::factory()->create();
        $expense  = Account::query()->where('type', 'expense')->where('code', '5010')->firstOrFail();

        $po = PurchaseOrder::factory()->create([
            'vendor_id'    => $vendor->id,
            'created_by'   => $by->id,
            'subtotal'     => '1000.00',
            'vat_amount'   => $isVatable ? '120.00' : '0.00',
            'total_amount' => $isVatable ? '1120.00' : '1000.00',
            'is_vatable'   => $isVatable,
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
            'vat_amount'        => $isVatable ? '120.00' : '0.00',
            'total_amount'      => $isVatable ? '1120.00' : '1000.00',
            'amount_paid'       => '0.00',
            'balance'           => $isVatable ? '1120.00' : '1000.00',
            'date'              => now()->toDateString(),
            'due_date'          => now()->addDays(30)->toDateString(),
            'is_vatable'        => $isVatable,
            // A supplier credit can only be applied to a bill that reached the
            // GL (CreditNoteService::apply → "The target bill does not have a
            // posted journal entry."). A billed GRN is posted in production, so
            // the fixture has to be too.
            'journal_entry_id'  => $this->postedJournalEntry($by)->id,
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

    public function test_supplier_return_uses_bill_price_in_base_units_and_returns_peso_totals(): void
    {
        $by = $this->admin();
        $ctx = $this->receivedShipment($by);
        $kg = Uom::create(['code' => 'KG', 'name' => 'Kilogram']);
        $bag = Uom::create(['code' => 'BAG', 'name' => 'Bag']);
        ItemUomConversion::create([
            'item_id' => $ctx['item']->id,
            'from_uom_id' => $bag->id,
            'to_uom_id' => $kg->id,
            'factor' => '5.000000',
        ]);
        $ctx['billItem']->update(['quantity' => '20', 'unit' => 'BAG', 'unit_price' => '60.00', 'total' => '1200.00']);
        $ctx['bill']->update(['subtotal' => '1200.00', 'vat_amount' => '144.00', 'total_amount' => '1344.00', 'balance' => '1344.00']);

        $created = $this->actingAs($by)->postJson('/api/v1/return-management/return-requests', [
            'type' => 'supplier_return',
            'vendor_id' => $ctx['vendor']->hash_id,
            'purchase_order_id' => $ctx['po']->hash_id,
            'bill_id' => $ctx['bill']->hash_id,
            'items' => [[
                'item_id' => $ctx['item']->hash_id,
                'quantity' => '5',
                'unit_price' => '1.00', // Request price cannot override billed provenance.
                'source_po_item_id' => $ctx['poItem']->hash_id,
                'source_grn_item_id' => $ctx['grnItem']->hash_id,
                'source_bill_item_id' => $ctx['billItem']->hash_id,
            ]],
        ])->assertCreated();

        $created->assertJsonPath('data.items.0.unit_price', '12.00')
            ->assertJsonPath('data.items.0.original_unit_price', '12.00')
            ->assertJsonPath('data.items.0.total', '60.00');
        $this->actingAs($by)
            ->getJson('/api/v1/return-management/return-requests/'.$created->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.items.0.source_allocation.unit_price', '12.00');
        $this->actingAs($by)
            ->postJson('/api/v1/return-management/return-requests/'.$created->json('data.id').'/submit')
            ->assertOk()
            ->assertJsonPath('data.items.0.total', '60.00');
    }

    public function test_supplier_replacement_po_converts_base_quantity_to_purchase_unit_and_compares_open_balance_in_base_units(): void
    {
        $admin = $this->admin();
        $ctx = $this->receivedShipment($admin, false);
        $kg = Uom::create(['code' => 'KG', 'name' => 'Kilogram']);
        $bag = Uom::create(['code' => 'BAG', 'name' => 'Bag']);
        ItemUomConversion::create([
            'item_id' => $ctx['item']->id,
            'from_uom_id' => $bag->id,
            'to_uom_id' => $kg->id,
            'factor' => '5.000000',
        ]);
        $ctx['poItem']->update([
            'quantity' => '1.000',
            'unit' => 'BAG',
            'unit_price' => '25.00',
            'total' => '25.00',
            'quantity_received' => '5.000',
        ]);
        $ctx['grnItem']->update([
            'quantity_received' => '5.000',
            'quantity_accepted' => '5.000',
            'unit_cost' => '25.0000',
        ]);
        $ctx['billItem']->update([
            'quantity' => '1.000',
            'unit' => 'BAG',
            'unit_price' => '25.00',
            'total' => '25.00',
        ]);
        $ctx['bill']->update([
            'subtotal' => '25.00',
            'vat_amount' => '0.00',
            'total_amount' => '25.00',
            'balance' => '25.00',
            'is_vatable' => false,
        ]);

        $created = $this->actingAs($admin)->postJson('/api/v1/return-management/return-requests', [
            'type' => 'supplier_return',
            'vendor_id' => $ctx['vendor']->hash_id,
            'purchase_order_id' => $ctx['po']->hash_id,
            'bill_id' => $ctx['bill']->hash_id,
            'items' => [[
                'item_id' => $ctx['item']->hash_id,
                'quantity' => '5.000',
                'source_po_item_id' => $ctx['poItem']->hash_id,
                'source_grn_item_id' => $ctx['grnItem']->hash_id,
                'source_bill_item_id' => $ctx['billItem']->hash_id,
            ]],
        ])->assertCreated()->json('data');
        $rmaId = $created['id'];
        $line = ReturnRequest::query()->firstOrFail()->items()->firstOrFail();

        $this->actingAs($admin)->postJson("/api/v1/return-management/return-requests/{$rmaId}/submit")
            ->assertOk();
        $this->actingAs($this->userWithRole('department_head'))
            ->postJson("/api/v1/return-management/return-requests/{$rmaId}/approve")
            ->assertOk();
        $this->actingAs($this->userWithRole('production_manager'))
            ->postJson("/api/v1/return-management/return-requests/{$rmaId}/approve")
            ->assertOk();
        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rmaId}/receive", [
                'received_quantities' => [$line->hash_id => '5.000'],
            ])
            ->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/return-management/return-requests/{$rmaId}/inspect")
            ->assertOk();
        $this->actingAs($admin)->postJson("/api/v1/return-management/return-requests/{$rmaId}/dispose", [
            'dispositions' => [[
                'item_id' => $line->hash_id,
                'disposition' => 'return_to_supplier',
            ]],
            'create_replacement_po' => true,
            'location_id' => $ctx['location']->hash_id,
        ])->assertOk();

        $rma = ReturnRequest::query()->firstOrFail();
        $replacement = PurchaseOrder::query()->findOrFail($rma->replacement_purchase_order_id);
        $replacementItem = $replacement->items()->firstOrFail();
        $this->assertSame('1.00', (string) $replacementItem->quantity);
        $this->assertSame('BAG', (string) $replacementItem->unit);
        $this->assertSame('25.00', (string) $replacementItem->unit_price);
        $this->assertNotNull($ctx['po']->fresh()->short_closed_at);
        $this->assertSame(0, bccomp((string) $ctx['poItem']->fresh()->quantity_received, '0', 3));
    }

    public function test_supplier_redelivery_case_keeps_bill_credit_and_po_acceptance_uses_base_units(): void
    {
        $admin = $this->admin();
        $ctx = $this->receivedShipment($admin, false);
        $kg = Uom::create(['code' => 'KG', 'name' => 'Kilogram']);
        $bag = Uom::create(['code' => 'BAG', 'name' => 'Bag']);
        ItemUomConversion::create([
            'item_id' => $ctx['item']->id,
            'from_uom_id' => $bag->id,
            'to_uom_id' => $kg->id,
            'factor' => '5.000000',
        ]);
        $ctx['poItem']->update([
            'quantity' => '1.00',
            'unit' => 'BAG',
            'unit_price' => '25.00',
            'total' => '25.00',
            'quantity_received' => '5.00',
            'quantity_accepted' => '5.00',
        ]);
        $ctx['grnItem']->update([
            'quantity_received' => '5.000',
            'quantity_accepted' => '5.000',
            'unit_cost' => '5.0000',
        ]);
        $ctx['billItem']->update([
            'quantity' => '1.00',
            'unit' => 'BAG',
            'unit_price' => '25.00',
            'total' => '25.00',
        ]);
        $ctx['bill']->update([
            'subtotal' => '25.00',
            'vat_amount' => '0.00',
            'total_amount' => '25.00',
            'balance' => '25.00',
            'is_vatable' => false,
        ]);

        $service = app(ReturnRequestService::class);
        $rma = $service->create([
            'type' => 'supplier_return',
            'vendor_id' => $ctx['vendor']->id,
            'purchase_order_id' => $ctx['po']->id,
            'bill_id' => $ctx['bill']->id,
            'items' => [[
                'item_id' => $ctx['item']->id,
                'quantity' => '1.000',
                'source_po_item_id' => $ctx['poItem']->id,
                'source_grn_item_id' => $ctx['grnItem']->id,
                'source_bill_item_id' => $ctx['billItem']->id,
            ]],
        ], $admin);
        $line = $rma->items->firstOrFail();
        $line->update(['returned_quantity' => '0.000', 'receipt_recorded' => false]);
        $rma->forceFill(['status' => ReturnRequestStatus::Approved])->save();
        $rma = $service->receive($rma, [(int) $line->id => '1.000'], null, $admin);
        $rma = $service->inspect($rma, 'Supplier return inspected.', $admin);

        $case = new ReturnCase();
        $case->forceFill([
            'case_number' => 'CASE-SUP-'.substr(uniqid(), -8),
            'type' => 'supplier',
            'status' => 'in_progress',
            'vendor_id' => $ctx['vendor']->id,
            'purchase_order_id' => $ctx['po']->id,
            'created_by' => $admin->id,
            'preferred_resolution' => 'redelivery',
            'resolution' => 'redelivery',
            'description' => 'Supplier agreed to redeliver the affected goods.',
            'return_request_id' => $rma->id,
        ])->save();

        $service->dispose($rma, [[
            'item_id' => $line->hash_id,
            'disposition' => 'return_to_supplier',
        ]], $admin, false, (int) $ctx['location']->id);

        $this->assertNotNull($rma->fresh()->credit_note_id, 'The supplier credit offsets the original payable.');
        $this->assertSame(1, CreditNote::query()->where('bill_id', $ctx['bill']->id)->count());
        $this->assertSame('20.00', (string) $ctx['bill']->fresh()->balance);
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $ctx['po']->fresh()->status);
        $this->assertSame(0, bccomp((string) $ctx['poItem']->fresh()->quantity_accepted, '4', 3));
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

        // ── Receive in two installments: 4 + 14 of the 20 claimed shipped back ──
        $line = $rma->items()->firstOrFail();
        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rmaId}/receive", [
                'received_quantities' => [$line->hash_id => 4],
                'final_receipt' => false,
                'request_key' => 'a1100000-0000-4000-8000-000000000001',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.items.0.returned_quantity', '4.000');

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rmaId}/receive", [
                'received_quantities' => [$line->hash_id => 14],
                'final_receipt' => true,
                'request_key' => 'a1100000-0000-4000-8000-000000000002',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'received');

        $this->assertSame('18.000', $line->fresh()->returned_quantity);
        $this->assertSame(2, $rma->receipts()->count());

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
        $this->assertSame($movement->id, (int) $line->fresh()->stock_movement_id);
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

    public function test_supplier_return_rejects_a_lot_that_differs_from_the_source_receipt(): void
    {
        $admin = $this->admin();
        $ctx = $this->receivedShipment($admin);
        $ctx['grnItem']->update(['material_lot_number' => 'SUPPLIER-LOT-A']);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('lot provenance does not match');

        app(ReturnRequestService::class)->create([
            'type' => 'supplier_return',
            'vendor_id' => $ctx['vendor']->id,
            'purchase_order_id' => $ctx['po']->id,
            'bill_id' => $ctx['bill']->id,
            'items' => [[
                'item_id' => $ctx['item']->id,
                'quantity' => '1.000',
                'source_po_item_id' => $ctx['poItem']->id,
                'source_grn_item_id' => $ctx['grnItem']->id,
                'lot_number' => 'WRONG-LOT',
            ]],
        ], $admin);
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

    public function test_supplier_bill_line_must_belong_to_the_rma_purchase_order(): void
    {
        $admin = $this->admin();
        $ctx = $this->receivedShipment($admin);
        $otherPo = PurchaseOrder::factory()->create([
            'vendor_id' => $ctx['vendor']->id,
            'created_by' => $admin->id,
        ]);
        $otherBill = Bill::create([
            'bill_number' => 'BILL-OTHER-'.substr(uniqid(), -5),
            'vendor_id' => $ctx['vendor']->id,
            'purchase_order_id' => $otherPo->id,
            'status' => 'unpaid',
            'subtotal' => '100.00',
            'vat_amount' => '0.00',
            'total_amount' => '100.00',
            'amount_paid' => '0.00',
            'balance' => '100.00',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'journal_entry_id' => $ctx['bill']->journal_entry_id,
            'created_by' => $admin->id,
        ]);
        $otherBillItem = BillItem::create([
            'bill_id' => $otherBill->id,
            'expense_account_id' => Account::query()->where('type', 'expense')->where('code', '5010')->value('id'),
            'item_id' => $ctx['item']->id,
            'description' => 'Wrong PO source line',
            'quantity' => '10.00',
            'unit' => 'kg',
            'unit_price' => '10.00',
            'total' => '100.00',
        ]);

        try {
            app(\App\Modules\ReturnManagement\Services\ReturnRequestService::class)->create([
                'type' => 'supplier_return',
                'vendor_id' => $ctx['vendor']->id,
                'purchase_order_id' => $ctx['po']->id,
                'bill_id' => $otherBill->id,
                'items' => [[
                    'item_id' => $ctx['item']->id,
                    'quantity' => '1.000',
                    'source_po_item_id' => $ctx['poItem']->id,
                    'source_grn_item_id' => $ctx['grnItem']->id,
                    'source_bill_item_id' => $otherBillItem->id,
                ]],
            ], $admin);
            $this->fail('A bill line from a different purchase order must be rejected.');
        } catch (\App\Common\Exceptions\BusinessRuleException $e) {
            $this->assertStringContainsString('bill provenance', strtolower($e->getMessage()));
        }
    }

    public function test_replacement_purchase_order_inherits_source_non_vatable_treatment(): void
    {
        $admin = $this->admin();
        $ctx = $this->receivedShipment($admin, false);
        $rma = ReturnRequest::create([
            'rma_number' => 'RMA-VAT-'.substr(uniqid(), -5),
            'type' => 'supplier_return',
            'status' => ReturnRequestStatus::Inspected,
            'vendor_id' => $ctx['vendor']->id,
            'purchase_order_id' => $ctx['po']->id,
            'goods_receipt_note_id' => $ctx['grnItem']->goods_receipt_note_id,
            'bill_id' => $ctx['bill']->id,
            'created_by' => $admin->id,
        ]);
        $line = $rma->items()->create([
            'item_id' => $ctx['item']->id,
            'quantity' => '2.000',
            'returned_quantity' => '2.000',
            'unit_price' => '10.00',
            'source_po_item_id' => $ctx['poItem']->id,
            'source_grn_item_id' => $ctx['grnItem']->id,
            'source_bill_item_id' => $ctx['billItem']->id,
        ]);

        $disposed = app(\App\Modules\ReturnManagement\Services\ReturnRequestService::class)->dispose(
            $rma->load('items'),
            [['item_id' => $line->hash_id, 'disposition' => 'return_to_supplier']],
            $admin,
            true,
            $ctx['location']->id,
        );

        $replacement = $disposed->replacementPurchaseOrder;
        $this->assertNotNull($replacement);
        $this->assertFalse((bool) $replacement->is_vatable);
        $this->assertSame('0.00', (string) $replacement->vat_amount);
    }
}
