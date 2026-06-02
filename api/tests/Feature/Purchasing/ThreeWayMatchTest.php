<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Account;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Services\ThreeWayMatchService;
use App\Modules\Purchasing\Support\ThreeWayMatchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P2.4 — Pin 3-way match variance logic in ThreeWayMatchService::matchForPo().
 *
 * ┌─────────────────────────────────────────────────────────────────────────┐
 * │ VARIANCE FORMULA (from service lines 56-57)                             │
 * │   qtyVar   = abs(billQty   - poQty)   / poQty   * 100                  │
 * │   priceVar = abs(billPrice - poPrice) / poPrice * 100                   │
 * │                                                                          │
 * │ Comparisons (service lines 59-60):                                       │
 * │   qtyOk   = qtyVar   <= qtyTol    (AT threshold → ok, above → block)   │
 * │   priceOk = priceVar <= priceTol                                        │
 * │                                                                          │
 * │ Overall status logic (service lines 66-67):                              │
 * │   - If any line severity='block'          → overall = 'blocked'          │
 * │   - Else if any variance > 0              → overall = 'has_variances'   │
 * │   - Else (all variances exactly zero)     → overall = 'matched'          │
 * │                                                                          │
 * │ Line status values: 'matched' | 'qty_variance' | 'price_variance' | 'both' │
 * │ Tolerance source: SettingsService key                                    │
 * │   'purchasing.three_way_tolerance_qty_pct'   (default 5.0)              │
 * │   'purchasing.three_way_tolerance_price_pct' (default 5.0)              │
 * │                                                                          │
 * │ NOTE: GRN data is fetched but NOT used in the variance calculation.     │
 * │ Variances compare PO vs Bill only. GRN qty is informational in the      │
 * │ line output but does not affect ok/block status.                         │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
class ThreeWayMatchTest extends TestCase
{
    use RefreshDatabase;

    // ── Shared fixtures ──────────────────────────────────────────────────────

    private ThreeWayMatchService $service;
    private User $user;
    private Item $item;
    private WarehouseLocation $location;
    private Account $expenseAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ThreeWayMatchService::class);

        $this->user     = User::factory()->create();
        $this->item     = Item::factory()->create(['code' => 'RM-MATCH-001']);
        $this->location = WarehouseLocation::factory()->create();

        // A minimal expense account needed by BillItem.expense_account_id FK.
        $this->expenseAccount = Account::create([
            'code'           => '5010',
            'name'           => 'Purchases',
            'type'           => 'expense',
            'normal_balance' => 'debit',
            'is_active'      => true,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a PO with one line item.
     *
     * @param float $poQty      PO ordered quantity
     * @param float $poPrice    PO unit price
     */
    private function makePo(float $poQty, float $poPrice): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->create([
            'created_by' => $this->user->id,
            'status'     => 'approved',
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => $this->item->id,
            'description'       => 'Test item',
            'quantity'          => $poQty,
            'unit'              => 'pcs',
            'unit_price'        => $poPrice,
            'total'             => $poQty * $poPrice,
            'quantity_received' => 0,
        ]);

        return $po->fresh(['items.item']);
    }

    /**
     * Attach a GRN receipt to a PO item.
     * The GRN data is surfaced in line output but does NOT affect variance.
     *
     * @param PurchaseOrder $po
     * @param float         $qtyAccepted   GRN accepted qty
     * @param float         $unitCost      GRN unit cost
     */
    private function attachGrn(PurchaseOrder $po, float $qtyAccepted, float $unitCost): void
    {
        $poi = $po->items->first();

        $grn = GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $po->id,
            'vendor_id'         => $po->vendor_id,
            'received_by'       => $this->user->id,
        ]);

        GrnItem::create([
            'goods_receipt_note_id'  => $grn->id,
            'purchase_order_item_id' => $poi->id,
            'item_id'                => $this->item->id,
            'location_id'            => $this->location->id,
            'quantity_received'      => $qtyAccepted,
            'quantity_accepted'      => $qtyAccepted,
            'unit_cost'              => $unitCost,
        ]);
    }

    /**
     * Build the $billLines array that matchForPo() expects.
     * The service indexes bill lines by (string) poi->item_id (line 46).
     *
     * @param float $billQty
     * @param float $billPrice
     */
    private function makeBillLines(float $billQty, float $billPrice): array
    {
        return [
            [
                'item_id'     => (string) $this->item->id,   // must match poi->item_id
                'description' => 'Test item',
                'quantity'    => $billQty,
                'unit_price'  => $billPrice,
            ],
        ];
    }

    /**
     * Set the purchasing tolerance settings in the settings table so the
     * SettingsService picks them up (it falls back to default when the table
     * does not exist, so we must write via SettingsService::set() which also
     * invalidates the cache).
     */
    private function setTolerances(float $qtyPct, float $pricePct): void
    {
        $settings = app(SettingsService::class);
        $settings->set('purchasing.three_way_tolerance_qty_pct',   $qtyPct);
        $settings->set('purchasing.three_way_tolerance_price_pct', $pricePct);
    }

    // ════════════════════════════════════════════════════════════════════════
    // Test 1 — Exact match: PO == GRN == Bill → overall 'matched'
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Setup:
     *   PO:   qty=100, price=10.00
     *   GRN:  qty_accepted=100, unit_cost=10.00
     *   Bill: qty=100, unit_price=10.00
     *
     * qtyVar = abs(100-100)/100*100 = 0.0
     * priceVar = abs(10-10)/10*100  = 0.0
     *
     * Expected:
     *   overall_status = 'matched'
     *   line[0].status   = 'matched'
     *   line[0].severity = 'ok'
     */
    public function test_exact_match_returns_matched_status(): void
    {
        $po = $this->makePo(poQty: 100.0, poPrice: 10.00);
        $this->attachGrn($po, qtyAccepted: 100.0, unitCost: 10.00);
        $billLines = $this->makeBillLines(billQty: 100.0, billPrice: 10.00);

        $result = $this->service->matchForPo($po, $billLines);

        $this->assertInstanceOf(ThreeWayMatchResult::class, $result);
        $this->assertSame('matched', $result->overallStatus,
            'Exact PO/GRN/Bill match must return overall_status=matched');

        $line = $result->lines[0];
        $this->assertSame('matched', $line['status'],
            'Line status must be matched when both variances are zero');
        $this->assertSame('ok', $line['severity'],
            'Line severity must be ok for exact match');
        $this->assertSame(0.0, $line['quantity_variance_pct'],
            'Quantity variance pct must be 0 for exact match');
        $this->assertSame(0.0, $line['price_variance_pct'],
            'Price variance pct must be 0 for exact match');
    }

    // ════════════════════════════════════════════════════════════════════════
    // Test 2 — Qty within tolerance → 'has_variances' (not blocked)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Setup (5% tolerance, default):
     *   PO:   qty=100, price=10.00
     *   Bill: qty=103, price=10.00    → qtyVar = 3%  (< 5% tol)
     *
     * qtyVar = abs(103-100)/100*100 = 3.0  → qtyOk=true  (3 <= 5)
     * priceVar = 0.0                        → priceOk=true
     * severity = 'ok' (both ok)
     *
     * BUT qtyVar > 0 so overall lifts to 'has_variances'.
     *
     * Expected:
     *   overall_status = 'has_variances'
     *   line[0].status   = 'matched'   (line-level both fields ok)
     *   line[0].severity = 'ok'
     */
    public function test_qty_within_tolerance_returns_has_variances(): void
    {
        $po = $this->makePo(poQty: 100.0, poPrice: 10.00);
        $this->attachGrn($po, qtyAccepted: 103.0, unitCost: 10.00);
        $billLines = $this->makeBillLines(billQty: 103.0, billPrice: 10.00);

        $result = $this->service->matchForPo($po, $billLines);

        $this->assertSame('has_variances', $result->overallStatus,
            'A within-tolerance qty variance must lift overall to has_variances (not matched, not blocked)');

        $line = $result->lines[0];
        $this->assertSame('matched', $line['status'],
            'Line status is matched when both checks pass even with nonzero variance');
        $this->assertSame('ok', $line['severity'],
            'Line severity must be ok when variance is within tolerance');
        $this->assertSame(3.0, $line['quantity_variance_pct'],
            'Quantity variance pct must be 3.0');
    }

    // ════════════════════════════════════════════════════════════════════════
    // Test 3 — Qty above tolerance → 'blocked'
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Setup (5% tolerance, default):
     *   PO:   qty=100, price=10.00
     *   Bill: qty=110, price=10.00    → qtyVar = 10%  (> 5% tol)
     *
     * qtyVar = abs(110-100)/100*100 = 10.0 → qtyOk=false (10 > 5)
     * severity = 'block'
     *
     * Expected:
     *   overall_status = 'blocked'
     *   line[0].status   = 'qty_variance'
     *   line[0].severity = 'block'
     */
    public function test_qty_above_tolerance_returns_blocked(): void
    {
        $po = $this->makePo(poQty: 100.0, poPrice: 10.00);
        $this->attachGrn($po, qtyAccepted: 100.0, unitCost: 10.00);
        $billLines = $this->makeBillLines(billQty: 110.0, billPrice: 10.00);

        $result = $this->service->matchForPo($po, $billLines);

        $this->assertSame('blocked', $result->overallStatus,
            'A qty variance above tolerance must set overall_status=blocked');

        $line = $result->lines[0];
        $this->assertSame('qty_variance', $line['status'],
            'Line status must be qty_variance when only qty is out of tolerance');
        $this->assertSame('block', $line['severity'],
            'Line severity must be block when variance exceeds tolerance');
        $this->assertSame(10.0, $line['quantity_variance_pct'],
            'Quantity variance pct must be 10.0');
        $this->assertSame(0.0, $line['price_variance_pct'],
            'Price variance pct must be 0.0 when price matches exactly');
    }

    // ════════════════════════════════════════════════════════════════════════
    // Test 4 — Both qty & price out of tolerance → combined 'both' status
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Setup (5% tolerance, default):
     *   PO:   qty=100, price=10.00
     *   Bill: qty=115, price=11.00   → qtyVar=15%, priceVar=10% (both > 5%)
     *
     * qtyVar   = abs(115-100)/100*100 = 15.0 → qtyOk=false
     * priceVar = abs(11-10)/10*100    = 10.0 → priceOk=false
     * lineStatus = 'both'  (service line 64: !qtyOk && !priceOk → 'both')
     * severity   = 'block'
     *
     * Expected:
     *   overall_status = 'blocked'
     *   line[0].status   = 'both'
     *   line[0].severity = 'block'
     */
    public function test_both_qty_and_price_out_of_tolerance_returns_both_line_status(): void
    {
        $po = $this->makePo(poQty: 100.0, poPrice: 10.00);
        $this->attachGrn($po, qtyAccepted: 100.0, unitCost: 10.00);
        $billLines = $this->makeBillLines(billQty: 115.0, billPrice: 11.00);

        $result = $this->service->matchForPo($po, $billLines);

        $this->assertSame('blocked', $result->overallStatus,
            'Both-field variance above tolerance must set overall_status=blocked');

        $line = $result->lines[0];
        $this->assertSame('both', $line['status'],
            'Line status must be "both" when qty AND price are both out of tolerance');
        $this->assertSame('block', $line['severity'],
            'Line severity must be block when both variances exceed tolerance');
        $this->assertSame(15.0, $line['quantity_variance_pct'],
            'Quantity variance pct must be 15.0');
        $this->assertSame(10.0, $line['price_variance_pct'],
            'Price variance pct must be 10.0');
    }

    // ════════════════════════════════════════════════════════════════════════
    // Test 5a — Tolerance from settings: boundary AT threshold is NOT blocked
    // ════════════════════════════════════════════════════════════════════════

    /**
     * The comparison is `<=` (service line 59: qtyVar <= qtyTol).
     * A variance exactly AT the threshold must pass as ok (not blocked).
     *
     * Setup:
     *   Set tolerance to 3%.
     *   PO:   qty=100, price=10.00
     *   Bill: qty=103, price=10.00   → qtyVar = 3.0 (exactly AT 3%)
     *
     * qtyVar = 3.0 <= 3.0 → qtyOk=true → severity='ok'
     * qtyVar > 0 → overall = 'has_variances'  (not 'blocked')
     *
     * Expected:
     *   overall_status = 'has_variances'
     *   line[0].severity = 'ok'
     */
    public function test_variance_exactly_at_tolerance_threshold_is_not_blocked(): void
    {
        $this->setTolerances(qtyPct: 3.0, pricePct: 3.0);

        $po = $this->makePo(poQty: 100.0, poPrice: 10.00);
        $this->attachGrn($po, qtyAccepted: 100.0, unitCost: 10.00);
        $billLines = $this->makeBillLines(billQty: 103.0, billPrice: 10.00);

        $result = $this->service->matchForPo($po, $billLines);

        $this->assertSame('has_variances', $result->overallStatus,
            'Variance exactly AT the tolerance boundary must yield has_variances (not blocked)');
        $this->assertSame('ok', $result->lines[0]['severity'],
            'Severity must be ok when variance equals the tolerance threshold (<=)');
        $this->assertSame(3.0, $result->lines[0]['quantity_variance_pct'],
            'Variance pct must be 3.0 at the boundary');
    }

    // ════════════════════════════════════════════════════════════════════════
    // Test 5b — Tolerance from settings: one unit above threshold is blocked
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Setup:
     *   Set tolerance to 3%.
     *   PO:   qty=100, price=10.00
     *   Bill: qty=104, price=10.00   → qtyVar = 4.0 (> 3% tol)
     *
     * qtyVar = 4.0 > 3.0 → qtyOk=false → severity='block' → overall='blocked'
     *
     * Expected:
     *   overall_status = 'blocked'
     *   line[0].severity = 'block'
     *   tolerances in result = { qty_pct: 3.0, price_pct: 3.0 }
     */
    public function test_variance_above_tolerance_threshold_is_blocked(): void
    {
        $this->setTolerances(qtyPct: 3.0, pricePct: 3.0);

        $po = $this->makePo(poQty: 100.0, poPrice: 10.00);
        $this->attachGrn($po, qtyAccepted: 100.0, unitCost: 10.00);
        $billLines = $this->makeBillLines(billQty: 104.0, billPrice: 10.00);

        $result = $this->service->matchForPo($po, $billLines);

        $this->assertSame('blocked', $result->overallStatus,
            'A variance above the tolerance threshold must yield blocked');
        $this->assertSame('block', $result->lines[0]['severity'],
            'Severity must be block when variance exceeds tolerance');
        $this->assertSame(4.0, $result->lines[0]['quantity_variance_pct'],
            'Variance pct must be 4.0');

        // Confirm the tolerances loaded from settings are reflected in the result.
        $this->assertSame(3.0, $result->tolerances['qty_pct'],
            'Result must carry the tolerance that was loaded from settings');
        $this->assertSame(3.0, $result->tolerances['price_pct'],
            'Result must carry the price tolerance that was loaded from settings');
    }

    // ════════════════════════════════════════════════════════════════════════
    // Test 6 — Result shape: required fields are present on lines
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Confirm the line array has all documented keys so callers don't break
     * when consuming the result.
     */
    public function test_result_line_contains_all_expected_keys(): void
    {
        $po = $this->makePo(poQty: 50.0, poPrice: 20.00);
        $billLines = $this->makeBillLines(billQty: 50.0, billPrice: 20.00);

        $result = $this->service->matchForPo($po, $billLines);

        $expectedKeys = [
            'item_id', 'item_code', 'description',
            'po_quantity', 'po_unit_price', 'po_total',
            'grn_quantity_accepted', 'grn_unit_cost',
            'bill_quantity', 'bill_unit_price', 'bill_total',
            'quantity_variance_pct', 'price_variance_pct',
            'status', 'severity',
        ];

        $line = $result->lines[0];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $line,
                "Line must contain key '{$key}'");
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // Test 7 — price_variance line status when only price is out of tolerance
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Setup (5% tolerance, default):
     *   PO:   qty=100, price=10.00
     *   Bill: qty=100, price=10.60   → priceVar=6% (> 5% tol), qtyVar=0
     *
     * priceVar = abs(10.60-10.00)/10.00*100 = 6.0 → priceOk=false
     * qtyOk=true
     * lineStatus = 'price_variance'  (service line 64: qtyOk && !priceOk → 'price_variance')
     *
     * Expected:
     *   overall_status = 'blocked'
     *   line[0].status   = 'price_variance'
     *   line[0].severity = 'block'
     */
    public function test_only_price_out_of_tolerance_returns_price_variance_line_status(): void
    {
        $po = $this->makePo(poQty: 100.0, poPrice: 10.00);
        $this->attachGrn($po, qtyAccepted: 100.0, unitCost: 10.00);
        $billLines = $this->makeBillLines(billQty: 100.0, billPrice: 10.60);

        $result = $this->service->matchForPo($po, $billLines);

        $this->assertSame('blocked', $result->overallStatus,
            'A price variance above tolerance must set overall_status=blocked');

        $line = $result->lines[0];
        $this->assertSame('price_variance', $line['status'],
            'Line status must be price_variance when only price is out of tolerance');
        $this->assertSame('block', $line['severity'],
            'Line severity must be block when price exceeds tolerance');
        $this->assertSame(0.0, $line['quantity_variance_pct'],
            'Quantity variance pct must be 0.0 when qty matches exactly');
        $this->assertSame(6.0, $line['price_variance_pct'],
            'Price variance pct must be 6.0');
    }

    // ════════════════════════════════════════════════════════════════════════
    // Test 8 — Result DTO has the correct po_id and po_number
    // ════════════════════════════════════════════════════════════════════════

    /**
     * The ThreeWayMatchResult DTO carries poId (raw int) and poNumber.
     * Verify the correct PO is identified in the result.
     */
    public function test_result_carries_correct_po_id_and_number(): void
    {
        $po = $this->makePo(poQty: 10.0, poPrice: 5.00);
        $billLines = $this->makeBillLines(billQty: 10.0, billPrice: 5.00);

        $result = $this->service->matchForPo($po, $billLines);

        $this->assertSame($po->id,        $result->poId,
            'Result poId must match the PO primary key');
        $this->assertSame($po->po_number, $result->poNumber,
            'Result poNumber must match the PO number');
    }
}
