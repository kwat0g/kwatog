<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Common\Services\SettingsService;
use App\Common\Support\Money;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\GrnGlPostingService;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Models\Inspection;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BUG A — GRNI residual / no purchase price variance (PPV)
 *
 * When a bill's price differs from the GRN's unit cost (within 3-way variance
 * tolerance), the current implementation posts GRNI at the bill's subtotal,
 * leaving a residual on the GRNI account. The fix:
 *
 * 1. Calculate what the GRN posted to GRNI (sum of credits on posted JEs with
 *    reference_type='goods_receipt_note' and reference_id=$grn_id)
 * 2. Debit GRNI with exactly that amount
 * 3. Post the difference (bill - GRNI credit) to account 5040 (Purchase Price
 *    Variance): debit when bill > GRNI, credit when bill < GRNI
 */
class BillPriceVarianceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    // Maker-checker: an incoming inspection counts only when a different user checks it.
    private User $checker;

    private GrnService $grnSvc;

    private BillService $billSvc;

    private GrnGlPostingService $grnGlSvc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        // Enable accounting module
        $settingsSvc = app(SettingsService::class);
        $settingsSvc->set('modules.accounting', true);

        // Use system_admin role which has all permissions including exception_approve
        $this->user = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
            'is_active' => true,
        ]);
        $this->checker = User::factory()->create(['is_active' => true]);

        $settingsSvc->set('system.automation.actor_roles', ['system_admin']);

        $this->grnSvc = app(GrnService::class);
        $this->billSvc = app(BillService::class);
        $this->grnGlSvc = app(GrnGlPostingService::class);
    }

    /** Build an approved PO (100 kg @ 12.50) and receive 100 kg into an accepted GRN. */
    private function makeAcceptedGrn(string $unitCost = '12.50'): GoodsReceiptNote
    {
        $item = Item::factory()->create([
            'is_active' => true,
            'item_type' => ItemType::RawMaterial,
        ]);
        $po = PurchaseOrder::factory()->create([
            'status'     => PurchaseOrderStatus::Approved->value,
            'is_vatable' => false,
            'created_by' => $this->user->id,
            'vendor_id'  => \App\Modules\Accounting\Models\Vendor::factory()->create()->id,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => $item->id,
            'description'       => 'Resin batch',
            'quantity'          => '100.000',
            'unit'              => 'kg',
            'unit_price'        => $unitCost,
            'total'             => bcmul('100', $unitCost, 2),
            'quantity_received' => '0.000',
        ]);

        $grn = $this->grnSvc->create($po, [[
            'purchase_order_item_id' => $poItem->id,
            'item_id'                => $item->id,
            'location_id'            => WarehouseLocation::factory()->create()->id,
            'quantity_received'      => '100.000',
            'unit_cost'              => $unitCost,
        ]], ['received_date' => now()->toDateString()], $this->user);

        // Create and pass inspection records for all items
        $inspections = Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->get();

        foreach ($inspections as $inspection) {
            $inspection->update(['status' => 'passed', 'inspector_id' => $this->user->id, 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()]);
        }

        // If no inspections exist, create one (shouldn't happen with recent seeders but just in case)
        if ($inspections->isEmpty()) {
            Inspection::create([
                'entity_type' => 'grn',
                'entity_id' => $grn->id,
                'inspection_type' => 'incoming',
                'status' => 'passed',
            ]);
        }

        // Accept the GRN and post GL
        $grn = $this->grnSvc->accept($grn, $this->user);
        $this->grnGlSvc->post($grn);

        return $grn->fresh();
    }

    /** Cancel any auto-draft bill created by GRN acceptance, to allow manual bill creation. */
    private function cancelAutoDraftBill(GoodsReceiptNote $grn): void
    {
        $draftBill = Bill::query()
            ->where('goods_receipt_note_id', $grn->id)
            ->where('status', BillStatus::Draft->value)
            ->first();

        if ($draftBill) {
            $this->billSvc->cancel($draftBill, $this->user);
        }
    }

    public function test_bill_price_higher_than_po_posts_ppv_debit(): void
    {
        // GRN: 100 kg @ 12.50 = 1250.00
        $grn = $this->makeAcceptedGrn('12.50');
        // Cancel the auto-draft bill created by acceptance so we can create a manual bill
        $this->cancelAutoDraftBill($grn);

        $po = $grn->purchaseOrder;
        $vendor = $grn->vendor;

        // Bill for 100 kg @ 12.63 (higher price, within 3-way tolerance) = 1263.00
        // Variance = 1263.00 - 1250.00 = 13.00 (debit PPV)
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->id;
        $bill = $this->billSvc->create([
            'bill_number' => 'TEST-PPV-001',
            'vendor_id'   => $vendor->hash_id,
            'purchase_order_id' => $po->hash_id,
            'goods_receipt_note_id' => $grn->hash_id,
            'provenance_type' => 'stock',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'is_vatable' => false,
            'items' => [
                [
                    'item_id' => $grn->items[0]->item_id,
                    'description' => 'Resin batch',
                    'quantity' => '100',
                    'unit_price' => '12.63',
                    'expense_account_id' => $expenseId,
                ],
            ],
        ], $this->user);

        // Bill total should be 1263.00
        $this->assertSame('1263.00', (string) $bill->subtotal);

        // JE should be posted and balanced
        $je = $bill->journalEntry;
        $this->assertNotNull($je);
        $this->assertSame('posted', $je->status->value);
        $this->assertSame((string) $je->total_debit, (string) $je->total_credit);

        // Find the GRNI line in the JE: should be debited with exactly the GRN posted amount (1250.00)
        $grniCode = app(SettingsService::class)->requiredString('accounting.accounts.grni_code');
        $grnLines = DB::table('journal_entry_lines as line')
            ->join('accounts as account', 'account.id', '=', 'line.account_id')
            ->where('line.journal_entry_id', $je->id)
            ->where('account.code', $grniCode)
            ->select('line.debit', 'line.credit')
            ->get();

        $this->assertCount(1, $grnLines);
        $this->assertSame('1250.00', (string) $grnLines[0]->debit);
        $this->assertSame('0.00', (string) $grnLines[0]->credit);

        // Find the PPV line in the JE: should be debited with the difference (13.00)
        $ppvCode = app(SettingsService::class)->requiredString('accounting.accounts.purchase_price_variance_code');
        $ppvLines = DB::table('journal_entry_lines as line')
            ->join('accounts as account', 'account.id', '=', 'line.account_id')
            ->where('line.journal_entry_id', $je->id)
            ->where('account.code', $ppvCode)
            ->select('line.debit', 'line.credit')
            ->get();

        $this->assertCount(1, $ppvLines);
        $this->assertSame('13.00', (string) $ppvLines[0]->debit);
        $this->assertSame('0.00', (string) $ppvLines[0]->credit);
    }

    public function test_bill_price_lower_than_po_posts_ppv_credit(): void
    {
        // GRN: 100 kg @ 12.50 = 1250.00
        $grn = $this->makeAcceptedGrn('12.50');
        // Cancel the auto-draft bill created by acceptance so we can create a manual bill
        $this->cancelAutoDraftBill($grn);

        $po = $grn->purchaseOrder;
        $vendor = $grn->vendor;

        // Bill for 100 kg @ 12.38 (lower price, within 3-way tolerance) = 1238.00
        // Variance = 1238.00 - 1250.00 = -12.00 (credit PPV)
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->id;
        $bill = $this->billSvc->create([
            'bill_number' => 'TEST-PPV-002',
            'vendor_id'   => $vendor->hash_id,
            'purchase_order_id' => $po->hash_id,
            'goods_receipt_note_id' => $grn->hash_id,
            'provenance_type' => 'stock',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'is_vatable' => false,
            'items' => [
                [
                    'item_id' => $grn->items[0]->item_id,
                    'description' => 'Resin batch',
                    'quantity' => '100',
                    'unit_price' => '12.38',
                    'expense_account_id' => $expenseId,
                ],
            ],
        ], $this->user);

        // Bill total should be 1238.00
        $this->assertSame('1238.00', (string) $bill->subtotal);

        $je = $bill->journalEntry;
        $this->assertNotNull($je);

        // GRNI should be debited with exactly 1250.00
        $grniCode = app(SettingsService::class)->requiredString('accounting.accounts.grni_code');
        $grnLines = DB::table('journal_entry_lines as line')
            ->join('accounts as account', 'account.id', '=', 'line.account_id')
            ->where('line.journal_entry_id', $je->id)
            ->where('account.code', $grniCode)
            ->select('line.debit', 'line.credit')
            ->get();

        $this->assertCount(1, $grnLines);
        $this->assertSame('1250.00', (string) $grnLines[0]->debit);

        // PPV should be credited with 12.00
        $ppvCode = app(SettingsService::class)->requiredString('accounting.accounts.purchase_price_variance_code');
        $ppvLines = DB::table('journal_entry_lines as line')
            ->join('accounts as account', 'account.id', '=', 'line.account_id')
            ->where('line.journal_entry_id', $je->id)
            ->where('account.code', $ppvCode)
            ->select('line.debit', 'line.credit')
            ->get();

        $this->assertCount(1, $ppvLines);
        $this->assertSame('0.00', (string) $ppvLines[0]->debit);
        $this->assertSame('12.00', (string) $ppvLines[0]->credit);
    }

    public function test_bill_price_equal_to_po_posts_no_ppv(): void
    {
        // GRN: 100 kg @ 12.50 = 1250.00
        $grn = $this->makeAcceptedGrn('12.50');
        // Cancel the auto-draft bill created by acceptance so we can create a manual bill
        $this->cancelAutoDraftBill($grn);

        $po = $grn->purchaseOrder;
        $vendor = $grn->vendor;

        // Bill for 100 kg @ 12.50 (same price) = 1250.00
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->id;
        $bill = $this->billSvc->create([
            'bill_number' => 'TEST-PPV-003',
            'vendor_id'   => $vendor->hash_id,
            'purchase_order_id' => $po->hash_id,
            'goods_receipt_note_id' => $grn->hash_id,
            'provenance_type' => 'stock',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'is_vatable' => false,
            'items' => [
                [
                    'item_id' => $grn->items[0]->item_id,
                    'description' => 'Resin batch',
                    'quantity' => '100',
                    'unit_price' => '12.50',
                    'expense_account_id' => $expenseId,
                ],
            ],
        ], $this->user);

        $je = $bill->journalEntry;
        $this->assertNotNull($je);

        // PPV should not have a line at all when prices match
        $ppvCode = app(SettingsService::class)->requiredString('accounting.accounts.purchase_price_variance_code');
        $ppvLines = DB::table('journal_entry_lines as line')
            ->join('accounts as account', 'account.id', '=', 'line.account_id')
            ->where('line.journal_entry_id', $je->id)
            ->where('account.code', $ppvCode)
            ->count();

        $this->assertSame(0, $ppvLines);
    }

    /**
     * Service bill lines cannot reference inventory items, as all ItemType cases
     * are tracked inventory that must be purchased via PO and received on GRN.
     */
    public function test_service_bill_rejects_raw_material_item(): void
    {
        $vendor = \App\Modules\Accounting\Models\Vendor::create(['name' => 'Test Vendor']);
        $item = Item::factory()->create([
            'is_active' => true,
            'item_type' => ItemType::RawMaterial,
        ]);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;

        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $this->expectExceptionMessage('Service bills cannot reference inventory items');

        $this->billSvc->create([
            'bill_number' => 'SVC-001',
            'vendor_id' => $vendor->hash_id,
            'provenance_type' => 'service',
            'exception_evidence' => 'Test evidence',
            'exception_approved' => true,
            'date' => now()->toDateString(),
            'is_vatable' => false,
            'items' => [
                [
                    'item_id' => $item->hash_id,
                    'description' => 'Raw material',
                    'quantity' => '10',
                    'unit_price' => '100.00',
                    'expense_account_id' => $expenseId,
                ],
            ],
        ], $this->user);
    }

    public function test_service_bill_rejects_finished_good_item(): void
    {
        $vendor = \App\Modules\Accounting\Models\Vendor::create(['name' => 'Test Vendor']);
        $item = Item::factory()->create([
            'is_active' => true,
            'item_type' => ItemType::FinishedGood,
        ]);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;

        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $this->expectExceptionMessage('Service bills cannot reference inventory items');

        $this->billSvc->create([
            'bill_number' => 'SVC-002',
            'vendor_id' => $vendor->hash_id,
            'provenance_type' => 'service',
            'exception_evidence' => 'Test evidence',
            'exception_approved' => true,
            'date' => now()->toDateString(),
            'is_vatable' => false,
            'items' => [
                [
                    'item_id' => $item->hash_id,
                    'description' => 'Finished good',
                    'quantity' => '10',
                    'unit_price' => '100.00',
                    'expense_account_id' => $expenseId,
                ],
            ],
        ], $this->user);
    }

    public function test_service_bill_rejects_packaging_item(): void
    {
        $vendor = \App\Modules\Accounting\Models\Vendor::create(['name' => 'Test Vendor']);
        $item = Item::factory()->create([
            'is_active' => true,
            'item_type' => ItemType::Packaging,
        ]);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;

        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $this->expectExceptionMessage('Service bills cannot reference inventory items');

        $this->billSvc->create([
            'bill_number' => 'SVC-003',
            'vendor_id' => $vendor->hash_id,
            'provenance_type' => 'service',
            'exception_evidence' => 'Test evidence',
            'exception_approved' => true,
            'date' => now()->toDateString(),
            'is_vatable' => false,
            'items' => [
                [
                    'item_id' => $item->hash_id,
                    'description' => 'Packaging',
                    'quantity' => '10',
                    'unit_price' => '100.00',
                    'expense_account_id' => $expenseId,
                ],
            ],
        ], $this->user);
    }

    public function test_service_bill_rejects_spare_part_item(): void
    {
        $vendor = \App\Modules\Accounting\Models\Vendor::create(['name' => 'Test Vendor']);
        $item = Item::factory()->create([
            'is_active' => true,
            'item_type' => ItemType::SparePart,
        ]);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;

        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $this->expectExceptionMessage('Service bills cannot reference inventory items');

        $this->billSvc->create([
            'bill_number' => 'SVC-004',
            'vendor_id' => $vendor->hash_id,
            'provenance_type' => 'service',
            'exception_evidence' => 'Test evidence',
            'exception_approved' => true,
            'date' => now()->toDateString(),
            'is_vatable' => false,
            'items' => [
                [
                    'item_id' => $item->hash_id,
                    'description' => 'Spare part',
                    'quantity' => '10',
                    'unit_price' => '100.00',
                    'expense_account_id' => $expenseId,
                ],
            ],
        ], $this->user);
    }

    public function test_service_bill_allows_line_with_no_item(): void
    {
        $vendor = \App\Modules\Accounting\Models\Vendor::create(['name' => 'Test Vendor']);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;

        // Should not throw
        $bill = $this->billSvc->create([
            'bill_number' => 'SVC-005',
            'vendor_id' => $vendor->hash_id,
            'provenance_type' => 'service',
            'exception_evidence' => 'Test evidence',
            'exception_approved' => true,
            'date' => now()->toDateString(),
            'is_vatable' => false,
            'items' => [
                [
                    // No item_id
                    'description' => 'Consulting service',
                    'quantity' => '10',
                    'unit_price' => '100.00',
                    'expense_account_id' => $expenseId,
                ],
            ],
        ], $this->user);

        $this->assertSame(BillStatus::Unpaid, $bill->status);
    }

    /**
     * Multi-bill scenario: GRN 100 accepted at 10.00 → draft bill A created,
     * posted, then further goods create draft bill B. GRNI debits across both
     * must sum to GRN total credit, with zero PPV when prices match.
     *
     * This verifies the fix for double-clearing: remainingGrniForGrn() must
     * account for GRNI already debited by OTHER bills on the same GRN.
     */
    public function test_multiple_bills_on_same_grn_clear_grni_once(): void
    {
        // Create a simple multi-bill scenario using createDraftForGrn
        // (the auto-bill listener path) which is the real-world case.
        $item = Item::factory()->create([
            'is_active' => true,
            'item_type' => ItemType::RawMaterial,
        ]);
        $po = PurchaseOrder::factory()->create([
            'status'     => PurchaseOrderStatus::Approved->value,
            'is_vatable' => false,
            'created_by' => $this->user->id,
            'vendor_id'  => \App\Modules\Accounting\Models\Vendor::factory()->create()->id,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => $item->id,
            'description'       => 'Test batch',
            'quantity'          => '100.000',
            'unit'              => 'kg',
            'unit_price'        => '10.00',
            'total'             => '1000.00',
            'quantity_received' => '0.000',
        ]);

        // Create GRN and accept 60 kg
        $grn = $this->grnSvc->create($po, [[
            'purchase_order_item_id' => $poItem->id,
            'item_id'                => $item->id,
            'location_id'            => WarehouseLocation::factory()->create()->id,
            'quantity_received'      => '100.000',
            'unit_cost'              => '10.00',
        ]], ['received_date' => now()->toDateString()], $this->user);

        // Pass QC
        Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->update(['status' => 'passed', 'inspector_id' => $this->user->id, 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()]);

        // Accept 60 kg
        $grn = $this->grnSvc->partialAccept($grn, [
            $grn->items[0]->id => '60.000',
        ], $this->user);

        // Auto-draft should be created by listener. Post it (Bill A).
        $draftA = \App\Modules\Accounting\Models\Bill::query()
            ->where('goods_receipt_note_id', $grn->id)
            ->where('status', 'draft')
            ->firstOrFail();
        $billA = $this->billSvc->postDraft($draftA, $this->user);
        $grn->refresh(); // Reload to reflect posted bill

        // Further accept 40 kg (to 100 total)
        $grn = $this->grnSvc->partialAccept($grn, [
            $grn->items[0]->id => '100.000',
        ], $this->user);

        // Manually trigger draft creation for the second bill (the listener created one for the first partial accept)
        $draftB = $this->billSvc->createDraftForGrn($grn, $this->user);
        $this->assertNotNull($draftB, 'Second draft bill should be created for remaining goods');
        $billB = $this->billSvc->postDraft($draftB, $this->user);

        // Verify both bills posted
        $jeA = $billA->journalEntry;
        $jeB = $billB->journalEntry;
        $this->assertNotNull($jeA);
        $this->assertNotNull($jeB);

        // Sum GRNI debits across both bills
        $grniCode = app(SettingsService::class)->requiredString('accounting.accounts.grni_code');
        $totalGrniDebited = DB::table('journal_entry_lines as line')
            ->join('journal_entries as entry', 'entry.id', '=', 'line.journal_entry_id')
            ->join('accounts as account', 'account.id', '=', 'line.account_id')
            ->whereIn('entry.id', [$jeA->id, $jeB->id])
            ->where('account.code', $grniCode)
            ->sum('line.debit');

        // Should equal GRN's total GRNI credit (1000.00)
        $this->assertSame('1000.00', Money::round2((string) $totalGrniDebited));

        // Sum PPV across both bills — should be zero (prices match)
        $ppvCode = app(SettingsService::class)->requiredString('accounting.accounts.purchase_price_variance_code');
        $totalPpvDebit = DB::table('journal_entry_lines as line')
            ->join('journal_entries as entry', 'entry.id', '=', 'line.journal_entry_id')
            ->join('accounts as account', 'account.id', '=', 'line.account_id')
            ->whereIn('entry.id', [$jeA->id, $jeB->id])
            ->where('account.code', $ppvCode)
            ->sum('line.debit');
        $totalPpvCredit = DB::table('journal_entry_lines as line')
            ->join('journal_entries as entry', 'entry.id', '=', 'line.journal_entry_id')
            ->join('accounts as account', 'account.id', '=', 'line.account_id')
            ->whereIn('entry.id', [$jeA->id, $jeB->id])
            ->where('account.code', $ppvCode)
            ->sum('line.credit');

        $netPpv = Money::sub((string) $totalPpvDebit, (string) $totalPpvCredit);
        $this->assertTrue(Money::isZero($netPpv), "PPV should be zero when prices match, got {$netPpv}");
    }

    /**
     * Manual stock bill is refused when a posted (non-cancelled) bill already
     * exists on the GRN. The guard prevents double-billing.
     */
    public function test_manual_stock_bill_refused_when_posted_bill_exists(): void
    {
        $grn = $this->makeAcceptedGrn('12.50');
        $po = $grn->purchaseOrder;
        $vendor = $grn->vendor;

        // Get the auto-draft and post it
        $draftBill = Bill::query()
            ->where('goods_receipt_note_id', $grn->id)
            ->where('status', BillStatus::Draft->value)
            ->firstOrFail();
        $postedBill = $this->billSvc->postDraft($draftBill, $this->user);

        // Now try to create a manual bill — should be refused
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->id;

        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $this->expectExceptionMessage('An AP bill already exists');

        $this->billSvc->create([
            'bill_number' => 'TEST-MANUAL-REFUSED',
            'vendor_id' => $vendor->hash_id,
            'purchase_order_id' => $po->hash_id,
            'goods_receipt_note_id' => $grn->hash_id,
            'provenance_type' => 'stock',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'is_vatable' => false,
            'items' => [
                [
                    'item_id' => $grn->items[0]->item_id,
                    'description' => 'Resin batch',
                    'quantity' => '100',
                    'unit_price' => '13.00',
                    'expense_account_id' => $expenseId,
                ],
            ],
        ], $this->user);
    }

    /**
     * Manual stock bill is allowed after the auto-draft is cancelled,
     * and it posts PPV correctly when price differs from the GRN cost.
     */
    public function test_manual_stock_bill_allowed_after_draft_cancelled(): void
    {
        $grn = $this->makeAcceptedGrn('12.50');
        $po = $grn->purchaseOrder;
        $vendor = $grn->vendor;

        // Cancel the auto-draft
        $this->cancelAutoDraftBill($grn);

        // Now create a manual bill with a higher price — should succeed and post PPV
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->id;
        $bill = $this->billSvc->create([
            'bill_number' => 'TEST-MANUAL-ALLOWED',
            'vendor_id' => $vendor->hash_id,
            'purchase_order_id' => $po->hash_id,
            'goods_receipt_note_id' => $grn->hash_id,
            'provenance_type' => 'stock',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'is_vatable' => false,
            'items' => [
                [
                    'item_id' => $grn->items[0]->item_id,
                    'description' => 'Resin batch',
                    'quantity' => '100',
                    'unit_price' => '12.63', // Higher than GRN cost of 12.50, within tolerance
                    'expense_account_id' => $expenseId,
                ],
            ],
        ], $this->user);

        // Bill total should be 1263.00
        $this->assertSame('1263.00', (string) $bill->subtotal);

        // Should have PPV debit for 13.00 (1263 - 1250)
        $ppvCode = app(SettingsService::class)->requiredString('accounting.accounts.purchase_price_variance_code');
        $ppvLines = DB::table('journal_entry_lines as line')
            ->join('accounts as account', 'account.id', '=', 'line.account_id')
            ->where('line.journal_entry_id', $bill->journalEntry->id)
            ->where('account.code', $ppvCode)
            ->select('line.debit', 'line.credit')
            ->get();

        $this->assertCount(1, $ppvLines);
        $this->assertSame('13.00', (string) $ppvLines[0]->debit);
        $this->assertSame('0.00', (string) $ppvLines[0]->credit);
    }
}
