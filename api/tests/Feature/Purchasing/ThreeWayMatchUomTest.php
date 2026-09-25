<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Enums\BillStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\BillItem;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemUomConversion;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Services\ThreeWayMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThreeWayMatchUomTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Item $item;

    private PurchaseOrder $po;

    private PurchaseOrderItem $poLine;

    private WarehouseLocation $location;

    private Account $expenseAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->item = Item::factory()->create(['unit_of_measure' => 'KG']);
        $this->location = WarehouseLocation::factory()->create();
        $bag = Uom::create(['code' => 'BAG', 'name' => 'Bag']);
        $kg = Uom::create(['code' => 'KG', 'name' => 'Kilogram']);
        ItemUomConversion::create([
            'item_id' => $this->item->id,
            'from_uom_id' => $bag->id,
            'to_uom_id' => $kg->id,
            'factor' => '5.000000',
        ]);
        $this->po = PurchaseOrder::factory()->create(['created_by' => $this->user->id, 'status' => 'approved']);
        $this->poLine = PurchaseOrderItem::create([
            'purchase_order_id' => $this->po->id,
            'item_id' => $this->item->id,
            'description' => 'Resin',
            'quantity' => '2',
            'unit' => 'BAG',
            'unit_price' => '25.00',
            'total' => '50.00',
        ]);
        $this->expenseAccount = Account::create([
            'code' => '5010', 'name' => 'Purchases', 'type' => 'expense',
            'normal_balance' => 'debit', 'is_active' => true,
        ]);
    }

    private function receipt(string $quantity): GoodsReceiptNote
    {
        $grn = GoodsReceiptNote::factory()->create([
            'purchase_order_id' => $this->po->id,
            'vendor_id' => $this->po->vendor_id,
            'received_by' => $this->user->id,
            'status' => 'accepted',
        ]);
        GrnItem::create([
            'goods_receipt_note_id' => $grn->id,
            'purchase_order_item_id' => $this->poLine->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity_received' => $quantity,
            'quantity_accepted' => $quantity,
            'unit_cost' => '5.0000',
        ]);

        return $grn;
    }

    private function bill(GoodsReceiptNote $grn, string $quantity, BillStatus $status = BillStatus::Draft): Bill
    {
        $bill = Bill::create([
            'bill_number' => 'INV-T-'.substr(uniqid(), -5),
            'vendor_id' => $this->po->vendor_id,
            'purchase_order_id' => $this->po->id,
            'goods_receipt_note_id' => $grn->id,
            'provenance_type' => 'stock',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'is_vatable' => false,
            'subtotal' => '25.00',
            'vat_amount' => '0.00',
            'total_amount' => '25.00',
            'amount_paid' => '0.00',
            'balance' => '25.00',
            'status' => $status,
            'has_variances' => false,
            'created_by' => $this->user->id,
        ]);
        BillItem::create([
            'bill_id' => $bill->id,
            'expense_account_id' => $this->expenseAccount->id,
            'item_id' => $this->item->id,
            'description' => 'Resin',
            'quantity' => $quantity,
            'unit' => 'KG',
            'unit_price' => '5.0000',
            'total' => '25.00',
        ]);

        return $bill;
    }

    public function test_partial_base_unit_receipt_and_bill_match_purchase_unit_po(): void
    {
        $grn = $this->receipt('5.000');
        $bill = $this->bill($grn, '5.00');

        $result = app(ThreeWayMatchService::class)->matchForBill($bill);

        $this->assertSame('matched', $result->overallStatus);
        $this->assertSame('matched', $result->lines[0]['status']);
        $this->assertSame('10.00', $result->lines[0]['po_quantity']);
        $this->assertSame('5.00', $result->lines[0]['po_unit_price']);
        $this->assertSame('5.000', $result->lines[0]['grn_quantity_accepted']);
        $this->assertSame(0.0, $result->lines[0]['quantity_variance_pct']);
        $this->assertSame(0.0, $result->lines[0]['price_variance_pct']);
    }

    public function test_manual_bill_in_purchase_units_matches_base_unit_receipt(): void
    {
        $grn = $this->receipt('5.000');

        $bill = app(BillService::class)->createDraft([
            'bill_number' => 'INV-T-MANUAL',
            'vendor_id' => $this->po->vendor->hash_id,
            'purchase_order_id' => $this->po->hash_id,
            'goods_receipt_note_id' => $grn->hash_id,
            'provenance_type' => 'stock',
            'date' => now()->toDateString(),
            'is_vatable' => false,
            'items' => [[
                'expense_account_id' => $this->expenseAccount->hash_id,
                'item_id' => $this->item->hash_id,
                'description' => 'Resin',
                'quantity' => '1.00',
                'unit' => 'BAG',
                'unit_price' => '25.00',
            ]],
        ], $this->user);

        $this->assertSame('matched', $bill->three_way_match_snapshot['overall_status']);
        $this->assertSame('BAG', $bill->items->first()->unit);
        $this->assertSame('5.00', $bill->three_way_match_snapshot['lines'][0]['bill_quantity']);
        $this->assertSame('5.00', $bill->three_way_match_snapshot['lines'][0]['bill_unit_price']);
    }

    public function test_second_partial_receipt_uses_only_its_unbilled_base_units(): void
    {
        $first = $this->receipt('5.000');
        $this->bill($first, '5.00', BillStatus::Unpaid);
        $second = $this->receipt('5.000');
        $matcher = app(ThreeWayMatchService::class);

        $match = $matcher->matchForBill($this->bill($second, '5.00'));
        $this->assertSame('matched', $match->overallStatus);
        $this->assertSame('5.000', $match->lines[0]['grn_quantity_accepted']);

        $overclaim = $matcher->matchForPo($this->po, [[
            'item_id' => $this->item->id, 'quantity' => '6.00', 'unit' => 'KG', 'unit_price' => '5.00',
        ]], $second->id);
        $this->assertSame('blocked', $overclaim->overallStatus);
        $this->assertSame('grn_short', $overclaim->lines[0]['status']);
    }

    public function test_converted_price_at_tolerance_passes_and_above_it_blocks(): void
    {
        app(SettingsService::class)->set('purchasing.three_way_tolerance_price_pct', 5);
        $grn = $this->receipt('5.000');
        $matcher = app(ThreeWayMatchService::class);

        $atLimit = $matcher->matchForPo($this->po, [[
            'item_id' => $this->item->id, 'quantity' => '5.00', 'unit_price' => '5.25',
        ]], $grn->id);
        $this->assertSame('has_variances', $atLimit->overallStatus);
        $this->assertSame('ok', $atLimit->lines[0]['severity']);

        $overLimit = $matcher->matchForPo($this->po, [[
            'item_id' => $this->item->id, 'quantity' => '5.00', 'unit_price' => '5.26',
        ]], $grn->id);
        $this->assertSame('blocked', $overLimit->overallStatus);
        $this->assertSame('price_variance', $overLimit->lines[0]['status']);
    }

    public function test_previous_bill_in_bags_counts_as_base_units_for_po_wide_match(): void
    {
        $first = $this->receipt('5.000');
        $priorBill = $this->bill($first, '1.00', BillStatus::Unpaid);
        $priorBill->items()->firstOrFail()->update(['unit' => 'BAG', 'unit_price' => '25.0000']);
        $this->receipt('5.000');

        $result = app(ThreeWayMatchService::class)->matchForPo($this->po, [[
            'item_id' => $this->item->id, 'quantity' => '5.00', 'unit' => 'KG', 'unit_price' => '5.00',
        ]]);

        $this->assertSame('matched', $result->overallStatus);
        $this->assertSame('5.000', $result->lines[0]['grn_quantity_accepted']);
    }

    public function test_zero_conversion_factor_fails_without_dividing_by_zero(): void
    {
        ItemUomConversion::query()->where('item_id', $this->item->id)->update(['factor' => '0']);
        $grn = $this->receipt('5.000');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('positive base quantity');
        app(ThreeWayMatchService::class)->matchForPo($this->po, [[
            'item_id' => $this->item->id, 'quantity' => '5.00', 'unit_price' => '5.00',
        ]], $grn->id);
    }

    public function test_converted_price_and_quantity_tolerance_are_exact_at_the_boundary(): void
    {
        app(SettingsService::class)->set('purchasing.three_way_tolerance_qty_pct', 5);
        app(SettingsService::class)->set('purchasing.three_way_tolerance_price_pct', 5);
        $grn = $this->receipt('10.000');

        $result = app(ThreeWayMatchService::class)->matchForPo($this->po, [[
            'item_id' => $this->item->id, 'quantity' => '10.50', 'unit_price' => '5.25',
        ]], $grn->id);

        $this->assertSame('has_variances', $result->overallStatus);
        $this->assertSame('ok', $result->lines[0]['severity']);
        $this->assertSame(5.0, $result->lines[0]['quantity_variance_pct']);
        $this->assertSame(5.0, $result->lines[0]['price_variance_pct']);
        $this->assertSame('50.00', $result->lines[0]['po_total']);
        $this->assertSame('55.13', $result->lines[0]['bill_total']);
    }
}
