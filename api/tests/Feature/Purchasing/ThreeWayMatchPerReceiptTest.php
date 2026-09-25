<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\BillItem;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Services\ThreeWayMatchService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-receipt three-way matching prevents double-billing across multiple GRNs.
 *
 * A PO may have multiple receipts (GRNs). Each receipt creates a separate bill.
 * matchForPo must track which quantities have been billed against each GRN and
 * reject a second bill that re-claims those quantities.
 *
 * Bug scenario: PO 200 @ 10, GRN1 accepted 100, GRN2 accepted 100. Bill A
 * for GRN1 qty 100 posts fine. Bill B for GRN2 claims qty 200 and checks
 * cumulative GRN (100+100=200) vs bill (200) → "matched". But it re-claims
 * GRN1's 100 units already billed in Bill A. The fix: accept qty per bill
 * is max(0, grnQty - alreadyBilledOnOthers). Bill B qty 200 against GRN2 only
 * (100 available) → blocked.
 */
class ThreeWayMatchPerReceiptTest extends TestCase
{
    use RefreshDatabase;

    private ThreeWayMatchService $service;
    private BillService $billSvc;
    private User $actor;
    private User $checker;
    private Item $item;
    private WarehouseLocation $location;
    private Vendor $vendor;
    private Account $expenseAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->service  = app(ThreeWayMatchService::class);
        $this->billSvc  = app(BillService::class);
        $roleId         = Role::query()->where('slug', 'system_admin')->value('id');
        $this->actor    = User::create([
            'name' => 'Purchaser', 'email' => 'purchaser_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'), 'role_id' => $roleId, 'is_active' => true,
        ]);
        $this->checker  = User::create([
            'name' => 'Checker', 'email' => 'checker_'.uniqid().'@x.test',
            'password' => bcrypt('Password1!'), 'role_id' => $roleId, 'is_active' => true,
        ]);
        $this->vendor   = Vendor::create(['name' => 'Multi-Shipment Vendor', 'payment_terms_days' => 30]);
        $this->item     = Item::factory()->create(['code' => 'RM-MULTI-001', 'unit_of_measure' => 'kg']);
        $this->location = WarehouseLocation::factory()->create();
        $this->expenseAccount = Account::query()->where('code', '5010')->firstOrFail();
    }

    /** PO 200 @ 10. */
    private function makePo(): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->create([
            'created_by' => $this->actor->id,
            'vendor_id'  => $this->vendor->id,
            'status'     => PurchaseOrderStatus::Approved->value,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => $this->item->id,
            'description'       => 'Raw material',
            'quantity'          => '200.000',
            'unit'              => 'kg',
            'unit_price'        => '10.00',
            'total'             => '2000.00',
            'quantity_received' => 0,
        ]);
        return $po->fresh(['items.item']);
    }

    /** Create and accept a GRN for this PO. */
    private function createAndAcceptGrn(PurchaseOrder $po, float $qtyAccepted): GoodsReceiptNote
    {
        $poi = $po->items->first();
        $grn = GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $po->id,
            'vendor_id'         => $this->vendor->id,
            'received_by'       => $this->actor->id,
            'status'            => 'accepted',
            'accepted_by'       => $this->actor->id,
            'accepted_at'       => now(),
        ]);
        GrnItem::create([
            'goods_receipt_note_id'  => $grn->id,
            'purchase_order_item_id' => $poi->id,
            'item_id'                => $this->item->id,
            'location_id'            => $this->location->id,
            'quantity_received'      => $qtyAccepted,
            'quantity_accepted'      => $qtyAccepted,
            'unit_cost'              => '10.00',
        ]);
        return $grn;
    }

    /** Bill lines for a given quantity and price. */
    private function billLines(float $billQty, float $billPrice): array
    {
        return [[
            'item_id'     => (string) $this->item->id,
            'description' => 'Raw material',
            'quantity'    => $billQty,
            'unit_price'  => $billPrice,
        ]];
    }

    /**
     * Scenario a: PO 200, GRN1 accepted 100, GRN2 accepted 100.
     * Bill A (GRN1) qty 100 matches. Bill B (GRN2) qty 200 should block
     * because only 100 were accepted in GRN2.
     */
    public function test_second_bill_cannot_overclaim_its_grn_after_first_bill_posted(): void
    {
        $po = $this->makePo();
        $grn1 = $this->createAndAcceptGrn($po, 100.0);
        $grn2 = $this->createAndAcceptGrn($po, 100.0);

        // Bill A for GRN1: qty 100 should match.
        $result1 = $this->service->matchForPo($po, $this->billLines(100.0, 10.00), $grn1->id);
        $this->assertSame('matched', $result1->overallStatus);

        // Simulate Bill A being posted (create and post it).
        $billA = Bill::create([
            'bill_number'           => 'INV-A-001',
            'vendor_id'             => $this->vendor->id,
            'purchase_order_id'     => $po->id,
            'goods_receipt_note_id' => $grn1->id,
            'provenance_type'       => 'stock',
            'date'                  => now()->toDateString(),
            'due_date'              => now()->addDays(30)->toDateString(),
            'is_vatable'            => false,
            'subtotal'              => '1000.00',
            'vat_amount'            => '0.00',
            'total_amount'          => '1000.00',
            'amount_paid'           => '0.00',
            'balance'               => '1000.00',
            'status'                => BillStatus::Unpaid,
            'has_variances'         => false,
            'created_by'            => $this->actor->id,
        ]);
        BillItem::create([
            'bill_id'            => $billA->id,
            'expense_account_id' => $this->expenseAccount->id,
            'item_id'            => $this->item->id,
            'description'        => 'Raw material',
            'quantity'           => '100.00',
            'unit'               => 'kg',
            'unit_price'         => '10.00',
            'total'              => '1000.00',
        ]);

        // Bill B for GRN2: qty 200 should BLOCK. Without the fix,
        // this would have passed ("matched") because cumulative GRN is 200.
        $result2 = $this->service->matchForPo($po, $this->billLines(200.0, 10.00), $grn2->id);
        $this->assertSame('blocked', $result2->overallStatus,
            'Bill qty 200 against GRN2 (100 available) should block. Without the fix, it matches the cumulative GRN (200).'
        );
        $this->assertSame('grn_short', $result2->lines[0]['status']);
    }

    /**
     * Scenario b: Same PO/GRN setup, but Bill B for GRN2 qty 100 should match.
     */
    public function test_second_bill_matches_when_qty_within_its_grn(): void
    {
        $po = $this->makePo();
        $grn1 = $this->createAndAcceptGrn($po, 100.0);
        $grn2 = $this->createAndAcceptGrn($po, 100.0);

        // Bill A for GRN1: qty 100 matches and posts.
        $billA = Bill::create([
            'bill_number'           => 'INV-A-001',
            'vendor_id'             => $this->vendor->id,
            'purchase_order_id'     => $po->id,
            'goods_receipt_note_id' => $grn1->id,
            'provenance_type'       => 'stock',
            'date'                  => now()->toDateString(),
            'due_date'              => now()->addDays(30)->toDateString(),
            'is_vatable'            => false,
            'subtotal'              => '1000.00',
            'vat_amount'            => '0.00',
            'total_amount'          => '1000.00',
            'amount_paid'           => '0.00',
            'balance'               => '1000.00',
            'status'                => BillStatus::Unpaid,
            'has_variances'         => false,
            'created_by'            => $this->actor->id,
        ]);
        BillItem::create([
            'bill_id'            => $billA->id,
            'expense_account_id' => $this->expenseAccount->id,
            'item_id'            => $this->item->id,
            'description'        => 'Raw material',
            'quantity'           => '100.00',
            'unit'               => 'kg',
            'unit_price'         => '10.00',
            'total'              => '1000.00',
        ]);

        // Bill B for GRN2: qty 100 should match.
        $result2 = $this->service->matchForPo($po, $this->billLines(100.0, 10.00), $grn2->id);
        $this->assertSame('matched', $result2->overallStatus);
        $this->assertSame('ok', $result2->lines[0]['grn_status']);
    }

    /**
     * Scenario c: PO-wide path (no grnId parameter). After Bill A (qty 100)
     * is posted, a Bill B line of qty 200 should block and qty 100 should match.
     */
    public function test_po_wide_match_tracks_total_billed(): void
    {
        $po = $this->makePo();
        $grn1 = $this->createAndAcceptGrn($po, 100.0);
        $grn2 = $this->createAndAcceptGrn($po, 100.0);

        // Bill A (qty 100) posts.
        $billA = Bill::create([
            'bill_number'           => 'INV-A-001',
            'vendor_id'             => $this->vendor->id,
            'purchase_order_id'     => $po->id,
            'goods_receipt_note_id' => $grn1->id,
            'provenance_type'       => 'stock',
            'date'                  => now()->toDateString(),
            'due_date'              => now()->addDays(30)->toDateString(),
            'is_vatable'            => false,
            'subtotal'              => '1000.00',
            'vat_amount'            => '0.00',
            'total_amount'          => '1000.00',
            'amount_paid'           => '0.00',
            'balance'               => '1000.00',
            'status'                => BillStatus::Unpaid,
            'has_variances'         => false,
            'created_by'            => $this->actor->id,
        ]);
        BillItem::create([
            'bill_id'            => $billA->id,
            'expense_account_id' => $this->expenseAccount->id,
            'item_id'            => $this->item->id,
            'description'        => 'Raw material',
            'quantity'           => '100.00',
            'unit'               => 'kg',
            'unit_price'         => '10.00',
            'total'              => '1000.00',
        ]);

        // PO-wide match (no grnId): bill qty 200 should block (only 100 unbilled remains).
        $result = $this->service->matchForPo($po, $this->billLines(200.0, 10.00));
        $this->assertSame('blocked', $result->overallStatus);

        // PO-wide match (no grnId): bill qty 100 should match.
        $result = $this->service->matchForPo($po, $this->billLines(100.0, 10.00));
        $this->assertSame('matched', $result->overallStatus);
    }

    /**
     * Scenario d: Draft or cancelled bills do NOT reduce the available qty.
     */
    public function test_draft_and_cancelled_bills_do_not_reduce_available_qty(): void
    {
        $po = $this->makePo();
        $grn1 = $this->createAndAcceptGrn($po, 100.0);

        // Create a draft bill (not committed).
        $draftBill = Bill::create([
            'bill_number'           => 'INV-DRAFT-001',
            'vendor_id'             => $this->vendor->id,
            'purchase_order_id'     => $po->id,
            'goods_receipt_note_id' => $grn1->id,
            'provenance_type'       => 'stock',
            'date'                  => now()->toDateString(),
            'due_date'              => now()->addDays(30)->toDateString(),
            'is_vatable'            => false,
            'subtotal'              => '1000.00',
            'vat_amount'            => '0.00',
            'total_amount'          => '1000.00',
            'amount_paid'           => '0.00',
            'balance'               => '1000.00',
            'status'                => BillStatus::Draft,
            'has_variances'         => false,
            'created_by'            => $this->actor->id,
        ]);
        BillItem::create([
            'bill_id'            => $draftBill->id,
            'expense_account_id' => $this->expenseAccount->id,
            'item_id'            => $this->item->id,
            'description'        => 'Raw material',
            'quantity'           => '100.00',
            'unit'               => 'kg',
            'unit_price'         => '10.00',
            'total'              => '1000.00',
        ]);

        // Another bill claiming the same qty should NOT be blocked by the draft bill.
        $result = $this->service->matchForPo($po, $this->billLines(100.0, 10.00), $grn1->id);
        $this->assertSame('matched', $result->overallStatus);

        // Cancel the draft bill and test again.
        $draftBill->forceFill(['status' => BillStatus::Cancelled])->save();
        $result = $this->service->matchForPo($po, $this->billLines(100.0, 10.00), $grn1->id);
        $this->assertSame('matched', $result->overallStatus);
    }

    /**
     * Scenario e: excludeBillId prevents a bill from being blocked by itself.
     * An already-posted bill re-matched should not count itself.
     */
    public function test_posted_bill_rematched_does_not_count_itself(): void
    {
        $po = $this->makePo();
        $grn1 = $this->createAndAcceptGrn($po, 100.0);

        // Bill posts: qty 100.
        $bill = Bill::create([
            'bill_number'           => 'INV-001',
            'vendor_id'             => $this->vendor->id,
            'purchase_order_id'     => $po->id,
            'goods_receipt_note_id' => $grn1->id,
            'provenance_type'       => 'stock',
            'date'                  => now()->toDateString(),
            'due_date'              => now()->addDays(30)->toDateString(),
            'is_vatable'            => false,
            'subtotal'              => '1000.00',
            'vat_amount'            => '0.00',
            'total_amount'          => '1000.00',
            'amount_paid'           => '0.00',
            'balance'               => '1000.00',
            'status'                => BillStatus::Unpaid,
            'has_variances'         => false,
            'created_by'            => $this->actor->id,
        ]);
        BillItem::create([
            'bill_id'            => $bill->id,
            'expense_account_id' => $this->expenseAccount->id,
            'item_id'            => $this->item->id,
            'description'        => 'Raw material',
            'quantity'           => '100.00',
            'unit'               => 'kg',
            'unit_price'         => '10.00',
            'total'              => '1000.00',
        ]);

        // Re-match the same bill (e.g., during posting review). It should
        // be matched, not blocked by itself.
        $result = $this->service->matchForPo($po, $this->billLines(100.0, 10.00), $grn1->id, $bill->id);
        $this->assertSame('matched', $result->overallStatus);
    }
}
