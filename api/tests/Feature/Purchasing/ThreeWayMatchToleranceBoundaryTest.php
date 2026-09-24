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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Boundary tests for three-way match tolerance precision.
 *
 * Float rounding caused bills priced exactly at the tolerance to be blocked
 * ~50% of the time. E.g. PO 1.00, bill 1.05 at 5% tolerance computed as
 * 5.000000000000004 > 5 (blocked) instead of 5 == 5 (ok).
 *
 * This test suite ensures decimal-exact logic blocks ONLY when truly over,
 * not when exactly at the boundary.
 */
class ThreeWayMatchToleranceBoundaryTest extends TestCase
{
    use RefreshDatabase;

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
        $this->item     = Item::factory()->create(['code' => 'RM-BOUNDARY-001']);
        $this->location = WarehouseLocation::factory()->create();

        $this->expenseAccount = Account::create([
            'code'           => '5010',
            'name'           => 'Purchases',
            'type'           => 'expense',
            'normal_balance' => 'debit',
            'is_active'      => true,
        ]);
    }

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

    private function makeBillLines(float $billQty, float $billPrice): array
    {
        return [
            [
                'item_id'     => (string) $this->item->id,
                'description' => 'Test item',
                'quantity'    => $billQty,
                'unit_price'  => $billPrice,
            ],
        ];
    }

    private function setTolerances(float $qtyPct, float $pricePct): void
    {
        $settings = app(SettingsService::class);
        $settings->set('purchasing.three_way_tolerance_qty_pct',   $qtyPct);
        $settings->set('purchasing.three_way_tolerance_price_pct', $pricePct);
    }

    /**
     * Test that bills priced EXACTLY at the tolerance are matched (not blocked).
     *
     * This is the core bug fix: float rounding caused 1.00 → 1.05 (5% tol) to
     * compute as 5.000000000000004 > 5, blocking when it should pass.
     *
     * Test with multiple price points to cover the float rounding pathology.
     */
    public function test_bill_exactly_at_price_tolerance_matches(): void
    {
        $this->setTolerances(qtyPct: 5.0, pricePct: 5.0);

        $testCases = [
            ['poPrice' => 1.00, 'billPrice' => 1.05],
            ['poPrice' => 0.60, 'billPrice' => 0.63],
            ['poPrice' => 2.00, 'billPrice' => 2.10],
        ];

        foreach ($testCases as $case) {
            $poPrice = $case['poPrice'];
            $billPrice = $case['billPrice'];

            $po = $this->makePo(poQty: 100.0, poPrice: $poPrice);
            // GRN at same cost as PO, so price check is against PO only
            $this->attachGrn($po, qtyAccepted: 100.0, unitCost: $poPrice);
            $billLines = $this->makeBillLines(billQty: 100.0, billPrice: $billPrice);

            $result = $this->service->matchForPo($po, $billLines);

            $this->assertNotSame('blocked', $result->overallStatus,
                "Bill at PO {$poPrice} → {$billPrice} (5% tol) must not be blocked; got overall_status=blocked");

            $line = $result->lines[0];
            $this->assertSame('ok', $line['severity'],
                "Line at PO {$poPrice} → {$billPrice} must have severity=ok");
        }
    }

    /**
     * Test that bills priced one centavo ABOVE the tolerance are blocked.
     *
     * This confirms the tolerance boundary is actually enforced when exceeded.
     */
    public function test_bill_one_centavo_over_price_tolerance_blocks(): void
    {
        $this->setTolerances(qtyPct: 5.0, pricePct: 5.0);

        $po = $this->makePo(poQty: 100.0, poPrice: 1.00);
        $this->attachGrn($po, qtyAccepted: 100.0, unitCost: 1.00);
        // 1.06 is 6% over 1.00 → exceeds 5% tolerance
        $billLines = $this->makeBillLines(billQty: 100.0, billPrice: 1.06);

        $result = $this->service->matchForPo($po, $billLines);

        $this->assertSame('blocked', $result->overallStatus,
            'Bill at 1.06 (6% variance vs 5% tol) must be blocked');

        $line = $result->lines[0];
        $this->assertSame('block', $line['severity'],
            'Line at 1.06 must have severity=block');
    }

    /**
     * Test that bills with quantity EXACTLY at the tolerance are matched.
     *
     * QTY tolerance works the same way: an overage exactly at the tolerance
     * must pass, not block.
     */
    public function test_bill_qty_exactly_at_qty_tolerance_matches(): void
    {
        $this->setTolerances(qtyPct: 5.0, pricePct: 5.0);

        $po = $this->makePo(poQty: 20.0, poPrice: 100.00);
        $this->attachGrn($po, qtyAccepted: 20.0, unitCost: 100.00);
        // Bill qty 21 is 5% over 20 (exactly at tolerance)
        $billLines = $this->makeBillLines(billQty: 21.0, billPrice: 100.00);

        $result = $this->service->matchForPo($po, $billLines);

        $this->assertNotSame('blocked', $result->overallStatus,
            'Bill qty at 5% tolerance must not be blocked');

        $line = $result->lines[0];
        $this->assertSame('ok', $line['severity'],
            'Line at qty tolerance boundary must have severity=ok');
    }
}
