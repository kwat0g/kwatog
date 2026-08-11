<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\BillItem;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Events\GoodsReceiptNoteAccepted;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Exceptions\ThreeWayMatchException;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * 2026-08-08 — Auto-bill chain (P2P, link 3).
 *
 * When a GRN is fully accepted (goods moved into stock, inventory JE posted),
 * the GoodsReceiptNoteAccepted event fires and AutoCreateBillOnGrnAccepted
 * pre-creates the supplier bill as a DRAFT — lines from the accepted receipt
 * (quantity × unit cost), default expense account, vendor payment terms.
 * Nothing touches the ledger until accounting reviews and posts the draft:
 * postDraft() builds the AP/expense JE and flips the bill to unpaid.
 */
class AutoCreateBillOnGrnAcceptedTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private GrnService $grnSvc;

    private BillService $billSvc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $role = Role::firstOrCreate(['slug' => 'warehouse_staff'], ['name' => 'Warehouse Staff']);
        $this->user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        // Automation actor for the listener attribution.
        $admin = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
            'is_active' => true,
        ]);
        app(SettingsService::class)->set('system.automation.actor_roles', ['system_admin']);

        $this->grnSvc = app(GrnService::class);
        $this->billSvc = app(BillService::class);
    }

    /** Build an approved PO + line + a pending_qc GRN via the real service. */
    private function makePendingGrn(): GoodsReceiptNote
    {
        $item = Item::factory()->create(['is_active' => true]);
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'created_by' => $this->user->id,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => $item->id,
            'description'       => 'Resin batch',
            'quantity'          => '100.000',
            'unit'              => 'kg',
            'unit_price'        => '12.50',
            'total'             => '1250.00',
            'quantity_received' => '0.000',
        ]);
        $location = WarehouseLocation::factory()->create();

        return $this->grnSvc->create($po, [[
            'purchase_order_item_id' => $poItem->id,
            'item_id'                => $item->id,
            'location_id'            => $location->id,
            'quantity_received'      => '80.000',
            'unit_cost'              => '12.50',
        ]], ['received_date' => now()->toDateString()], $this->user);
    }

    public function test_accepting_a_grn_stages_a_draft_bill_prefilled_from_accepted_lines(): void
    {
        $grn = $this->makePendingGrn();

        // Pass the synchronous incoming inspection so accept() clears the QC gate.
        \App\Modules\Quality\Models\Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->update(['status' => 'passed']);

        $accepted = $this->grnSvc->accept($grn->fresh(), $this->user);
        $this->assertSame(GrnStatus::Accepted, $accepted->status);

        $bill = Bill::where('goods_receipt_note_id', $accepted->id)->first();
        $this->assertNotNull($bill, 'accepting a GRN must stage a draft supplier bill');
        $this->assertSame(BillStatus::Draft, $bill->status);
        $this->assertNull($bill->journal_entry_id, 'a draft bill must not touch the ledger');

        // Line pre-filled: 80 kg × 12.50 = 1000.00 subtotal, VAT 120, total 1120.
        $this->assertSame(1, $bill->items()->count());
        $this->assertSame('80.00', (string) $bill->items()->first()->quantity);
        $this->assertSame('12.50', (string) $bill->items()->first()->unit_price);
        $this->assertSame('1000.00', (string) $bill->subtotal);
        $this->assertSame('120.00', (string) $bill->vat_amount);
        $this->assertSame('1120.00', (string) $bill->total_amount);
        $this->assertSame($accepted->purchase_order_id, $bill->purchase_order_id);

        // Bill line must carry the item id from the accepted GRN line.
        $this->assertSame(
            $accepted->items()->first()->item_id,
            $bill->items()->first()->item_id,
        );
    }

    public function test_repeated_accepted_events_do_not_stack_bills(): void
    {
        $grn = $this->makePendingGrn();
        \App\Modules\Quality\Models\Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->update(['status' => 'passed']);
        $accepted = $this->grnSvc->accept($grn->fresh(), $this->user);

        // Replay the event — the listener + createDraftForGrn() guard must stay idempotent.
        event(new GoodsReceiptNoteAccepted($accepted));
        event(new GoodsReceiptNoteAccepted($accepted));

        $this->assertSame(
            1,
            Bill::where('goods_receipt_note_id', $accepted->id)->count(),
            'a duplicate accept event must not stack a second bill',
        );
    }

    public function test_partial_accept_does_not_stage_a_bill(): void
    {
        $grn = $this->makePendingGrn();
        \App\Modules\Quality\Models\Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->update(['status' => 'passed']);

        $partial = $this->grnSvc->partialAccept($grn->fresh(), [
            $grn->items()->first()->id => '40.000',
        ], $this->user);
        $this->assertSame(GrnStatus::PartialAccepted, $partial->status);

        // Dispatch the accepted event on the partially-accepted GRN — the
        // service's status guard (Accepted only) must skip bill creation.
        event(new GoodsReceiptNoteAccepted($partial));

        $this->assertSame(
            0,
            Bill::where('goods_receipt_note_id', $partial->id)->count(),
            'a partially-accepted GRN must not stage a bill',
        );
    }

    public function test_all_full_partial_accept_also_stages_a_bill(): void
    {
        // A partial accept that ends up covering every line transitions to
        // Accepted — the event must fire there too, so the bill is staged.
        $grn = $this->makePendingGrn();
        \App\Modules\Quality\Models\Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->update(['status' => 'passed']);

        $item = $grn->items()->first();
        $accepted = $this->grnSvc->partialAccept($grn->fresh(), [
            $item->id => (string) $item->quantity_received, // full qty
        ], $this->user);
        $this->assertSame(GrnStatus::Accepted, $accepted->status);

        $bill = Bill::where('goods_receipt_note_id', $accepted->id)->first();
        $this->assertNotNull($bill, 'an all-full partial accept lands on Accepted and must stage the bill');
        $this->assertSame(BillStatus::Draft, $bill->status);
    }

    public function test_listener_survives_missing_default_expense_account(): void
    {
        $grn = $this->makePendingGrn();
        // Mark accepted directly (bypassing accept() so no event fires yet).
        $grn->update([
            'status' => GrnStatus::Accepted,
            'accepted_at' => now(),
        ]);
        $accepted = $grn->fresh();

        // Wipe the default-expense-account setting so createDraftForGrn()
        // throws — the listener must expose the stateful failure to the queue
        // worker for retry/failed-job handling and leave no half-written bill.
        app(SettingsService::class)->set('accounting.default_expense_account_code', '');

        $listener = app(\App\Modules\Accounting\Listeners\AutoCreateBillOnGrnAccepted::class);
        try {
            $listener->handle(new GoodsReceiptNoteAccepted($accepted));
            $this->fail('A missing default expense account must fail the stateful chain step.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('default expense account', strtolower($e->getMessage()));
        }

        $this->assertSame(
            0,
            Bill::where('goods_receipt_note_id', $accepted->id)->count(),
            'a misconfigured expense account must not create a partial bill',
        );
    }

    public function test_missing_automation_actor_fails_the_stateful_chain_step(): void
    {
        $grn = $this->makePendingGrn();
        $grn->update([
            'status' => GrnStatus::Accepted,
            'accepted_at' => now(),
        ]);
        app(SettingsService::class)->set('system.automation.actor_roles', ['missing-role']);

        $listener = app(\App\Modules\Accounting\Listeners\AutoCreateBillOnGrnAccepted::class);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('no active automation actor');
        $listener->handle(new GoodsReceiptNoteAccepted($grn->fresh()));
    }

    public function test_post_draft_posts_the_je_and_flips_bill_to_unpaid(): void
    {
        $grn = $this->makePendingGrn();
        \App\Modules\Quality\Models\Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->update(['status' => 'passed']);
        $accepted = $this->grnSvc->accept($grn->fresh(), $this->user);

        $bill = Bill::where('goods_receipt_note_id', $accepted->id)->firstOrFail();
        $this->assertSame(BillStatus::Draft, $bill->status);

        $fin = User::factory()->create([
            'role_id' => Role::where('slug', 'finance_officer')->value('id'),
            'is_active' => true,
        ]);
        $posted = $this->billSvc->postDraft($bill->fresh(), $fin);

        $this->assertSame(BillStatus::Unpaid, $posted->status);
        $this->assertNotNull($posted->journal_entry_id, 'posting a draft bill must create the JE');

        $je = $posted->journalEntry;
        $this->assertSame('posted', $je->status->value);
        $this->assertSame((string) $je->total_debit, (string) $je->total_credit, 'JE must be balanced');
    }

    public function test_post_draft_rechecks_match_and_requires_an_audited_override(): void
    {
        $grn = $this->makePendingGrn();
        \App\Modules\Quality\Models\Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->update(['status' => 'passed']);
        $accepted = $this->grnSvc->accept($grn->fresh(), $this->user);

        $bill = Bill::where('goods_receipt_note_id', $accepted->id)->firstOrFail();
        PurchaseOrder::query()
            ->findOrFail($accepted->purchase_order_id)
            ->items()
            ->firstOrFail()
            ->update(['unit_price' => '30.00']);

        try {
            $this->billSvc->postDraft($bill->fresh(), $this->user);
            $this->fail('A changed PO price must block draft posting.');
        } catch (ThreeWayMatchException $e) {
            $this->assertSame('blocked', $e->details['overall_status']);
        }

        $reviewed = $bill->fresh();
        $this->assertSame(BillStatus::Draft, $reviewed->status);
        $this->assertSame('manual_review', $reviewed->threeWayReviewStatus());
        $this->assertNull($reviewed->journal_entry_id);

        $posted = $this->billSvc->postDraft(
            $reviewed,
            $this->user,
            allowOverride: true,
            overrideReason: 'Purchasing confirmed the approved price change against the supplier invoice.',
        );

        $this->assertSame(BillStatus::Unpaid, $posted->status);
        $this->assertTrue((bool) $posted->three_way_overridden);
        $this->assertSame('overridden', $posted->threeWayReviewStatus());
        $this->assertNotNull($posted->journal_entry_id);
    }

    public function test_auto_bill_uses_vendor_terms_for_due_date(): void
    {
        $grn = $this->makePendingGrn();
        \App\Modules\Quality\Models\Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->update(['status' => 'passed']);
        $accepted = $this->grnSvc->accept($grn->fresh(), $this->user);

        $bill = Bill::where('goods_receipt_note_id', $accepted->id)->firstOrFail();
        $terms = $accepted->vendor->payment_terms_days;

        $expectedDue = \Illuminate\Support\Carbon::parse($accepted->accepted_at)
            ->addDays($terms)->toDateString();
        $this->assertSame($expectedDue, $bill->due_date->toDateString());
    }
}
