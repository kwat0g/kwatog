<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\BillItem;
use App\Modules\DSS\Contracts\VerificationResult;
use App\Modules\DSS\Models\DssRecommendation;
use App\Modules\DSS\Verifiers\ThreeWayMatchReviewVerifier;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThreeWayMatchReviewVerifierTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;

    private PurchaseOrder $po;

    private Bill $bill;

    private Account $expenseAccount;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->set('purchasing.three_way_tolerance_qty_pct', 5);
        app(SettingsService::class)->set('purchasing.three_way_tolerance_price_pct', 5);

        $this->item = Item::factory()->create();
        $this->po = PurchaseOrder::factory()->create(['status' => 'approved']);
        PurchaseOrderItem::create([
            'purchase_order_id' => $this->po->id,
            'item_id' => $this->item->id,
            'description' => 'Test item',
            'quantity' => 1,
            'unit' => 'pcs',
            'unit_price' => 10,
            'total' => 10,
            'quantity_received' => 1,
        ]);
        $this->expenseAccount = Account::create([
            'code' => '5100',
            'name' => 'Test expense',
            'type' => 'expense',
            'normal_balance' => 'debit',
            'is_active' => true,
        ]);
        $this->bill = Bill::factory()->create([
            'vendor_id' => $this->po->vendor_id,
            'purchase_order_id' => $this->po->id,
            'status' => 'draft',
            'has_variances' => true,
            'three_way_overridden' => false,
            'three_way_match_snapshot' => ['overall_status' => 'blocked', 'lines' => []],
        ]);
        $this->addBillLine($this->item->id);
    }

    public function test_missing_source_returns_unavailable(): void
    {
        $id = $this->bill->id;
        $this->bill->delete();

        $result = $this->verify($id);

        $this->assertSame(VerificationResult::UNAVAILABLE, $result->status);
    }

    public function test_legacy_bill_line_without_item_id_returns_unavailable(): void
    {
        $this->bill->items()->delete();
        $this->addBillLine(null);

        $result = $this->verify($this->bill->id);

        $this->assertSame(VerificationResult::UNAVAILABLE, $result->status);
    }

    public function test_explicit_override_clears_bill_outside_detector_eligibility(): void
    {
        $this->bill->update(['three_way_overridden' => true]);

        $result = $this->verify($this->bill->id);

        $this->assertSame(VerificationResult::VERIFIED_CLEAR, $result->status);
        $this->assertSame('The bill is no longer eligible for blocked match review.', $result->proof);
    }

    public function test_current_blocked_match_remains_open(): void
    {
        $result = $this->verify($this->bill->id);

        $this->assertSame(VerificationResult::STILL_OPEN, $result->status);
    }

    public function test_current_non_blocked_match_clears_with_bounded_proof(): void
    {
        $grn = GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $this->po->id,
            'vendor_id' => $this->po->vendor_id,
            'status' => 'accepted',
        ]);
        $this->bill->update(['goods_receipt_note_id' => $grn->id]);
        GrnItem::create([
            'goods_receipt_note_id' => $grn->id,
            'purchase_order_item_id' => $this->po->items()->first()->id,
            'item_id' => $this->item->id,
            'location_id' => WarehouseLocation::factory()->create()->id,
            'quantity_received' => 1,
            'quantity_accepted' => 1,
            'unit_cost' => 10,
        ]);

        $result = $this->verify($this->bill->id);

        $this->assertSame(VerificationResult::VERIFIED_CLEAR, $result->status);
        $this->assertNotNull($result->observedAt);
        $this->assertSame('Current purchase order, bill, and receipt match is no longer blocked.', $result->proof);
        $this->assertDoesNotMatchRegularExpression('/[0-9]/', $result->proof);
    }

    private function addBillLine(?int $itemId): void
    {
        BillItem::create([
            'bill_id' => $this->bill->id,
            'expense_account_id' => $this->expenseAccount->id,
            'item_id' => $itemId,
            'description' => 'Test item',
            'quantity' => 1,
            'unit' => 'pcs',
            'unit_price' => 10,
            'total' => 10,
        ]);
    }

    private function verify(int $billId): VerificationResult
    {
        $recommendation = new DssRecommendation;
        $recommendation->detector_id = 'dss.three_way_match_review';
        $recommendation->verification_context = ['source_id' => $billId];

        return app(ThreeWayMatchReviewVerifier::class)->verify($recommendation);
    }
}
