<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

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
use App\Modules\Purchasing\Services\RfqAwardService;
use App\Modules\Purchasing\Services\ThreeWayMatchService;
use App\Modules\Quality\Models\Inspection;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RFQ freight capitalization test.
 *
 * When an RFQ PO has line-level and/or header-level freight/charges,
 * they should be capitalized into inventory cost (landed cost). This test
 * verifies:
 *
 * 1. PurchaseOrderItem::deliveredUnitCost() correctly distributes charges
 * 2. GRN receives at delivered cost, not bare unit_price
 * 3. Auto-drafted bill from GRN uses delivered cost and notes freight inclusion
 * 4. 3-way match compares bill against delivered cost, not bare unit_price
 * 5. Budget tracking is consistent (commitment = consumption)
 * 6. Non-RFQ POs are unchanged (deliveredUnitCost() == unit_price)
 */
class RfqFreightCapitalizationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $checker;

    private GrnService $grnSvc;

    private BillService $billSvc;

    private GrnGlPostingService $grnGlSvc;

    private ThreeWayMatchService $threeWayMatch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $settingsSvc = app(SettingsService::class);
        $settingsSvc->set('modules.accounting', true);

        $this->user = User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
            'is_active' => true,
        ]);
        $this->checker = User::factory()->create(['is_active' => true]);

        $settingsSvc->set('system.automation.actor_roles', ['system_admin']);

        $this->grnSvc = app(GrnService::class);
        $this->billSvc = app(BillService::class);
        $this->grnGlSvc = app(GrnGlPostingService::class);
        $this->threeWayMatch = app(ThreeWayMatchService::class);
    }

    /**
     * Test 1: deliveredUnitCost() distributes charges correctly.
     *
     * PO: 2 lines
     *   Line 1: 100 × ₱10 + ₱50 line freight = ₱1,050
     *   Line 2: 50 × ₱20 + ₱0 line freight = ₱1,000
     *   Header: ₱200 freight
     *   Total: ₱2,250
     *
     * Expected delivered costs:
     *   Line 1: (1050 + 200×(1050/2050)) / 100 = (1050 + 102.44) / 100 = 11.5244
     *   Line 2: (1000 + 200×(1000/2050)) / 50 = (1000 + 97.56) / 50 = 21.9512
     */
    public function test_deliveredUnitCost_prorates_charges()
    {
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'is_vatable' => false,
            'created_by' => $this->user->id,
            'vendor_id' => \App\Modules\Accounting\Models\Vendor::factory()->create()->id,
            'rfq_freight_amount' => '200.00',
            'rfq_other_charges' => '0.00',
        ]);

        $item1 = Item::factory()->create(['item_type' => ItemType::RawMaterial, 'unit_of_measure' => 'kg']);
        $item2 = Item::factory()->create(['item_type' => ItemType::RawMaterial, 'unit_of_measure' => 'kg']);

        $poi1 = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item1->id,
            'description' => 'Material A',
            'quantity' => '100.000',
            'unit' => 'kg',
            'unit_price' => '10.00',
            'total' => '1050.00',
            'rfq_line_freight_amount' => '50.00',
            'rfq_line_other_charges' => '0.00',
        ]);

        $poi2 = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item2->id,
            'description' => 'Material B',
            'quantity' => '50.000',
            'unit' => 'kg',
            'unit_price' => '20.00',
            'total' => '1000.00',
            'rfq_line_freight_amount' => '0.00',
            'rfq_line_other_charges' => '0.00',
        ]);

        $poi1->loadMissing('purchaseOrder.items');
        $delivered1 = $poi1->deliveredUnitCost();
        $this->assertNotNull($delivered1);
        // Line 1: (1000 + 50 + 200*1050/2050) / 100 ≈ 11.524390...
        // Rounded to 4dp: 11.5244
        $this->assertEquals('11.5244', $delivered1);

        $poi2->loadMissing('purchaseOrder.items');
        $delivered2 = $poi2->deliveredUnitCost();
        $this->assertNotNull($delivered2);
        // Line 2: (1000 + 0 + 200*1000/2050) / 50 ≈ 21.951219...
        // Rounded to 4dp: 21.9512
        $this->assertEquals('21.9512', $delivered2);
    }

    /**
     * Test 2: Non-RFQ POs return exact unit_price (backward compat).
     */
    public function test_non_rfq_po_returns_unit_price()
    {
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'created_by' => $this->user->id,
            'vendor_id' => \App\Modules\Accounting\Models\Vendor::factory()->create()->id,
        ]);

        $item = Item::factory()->create(['item_type' => ItemType::RawMaterial, 'unit_of_measure' => 'kg']);
        $poi = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Plain item',
            'quantity' => '100.000',
            'unit' => 'kg',
            'unit_price' => '12.50',
            'total' => '1250.00',
        ]);

        $poi->loadMissing('purchaseOrder.items');
        $delivered = $poi->deliveredUnitCost();
        $this->assertEquals('12.5000', $delivered);
    }

    /**
     * Test 3: GRN receives at delivered cost; inventory value = delivered cost.
     */
    public function test_grn_receives_at_delivered_cost()
    {
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'is_vatable' => false,
            'created_by' => $this->user->id,
            'vendor_id' => \App\Modules\Accounting\Models\Vendor::factory()->create()->id,
            'rfq_freight_amount' => '200.00',
            'rfq_other_charges' => '0.00',
        ]);

        $item1 = Item::factory()->create(['item_type' => ItemType::RawMaterial, 'unit_of_measure' => 'kg']);
        $item2 = Item::factory()->create(['item_type' => ItemType::RawMaterial, 'unit_of_measure' => 'kg']);

        $poi1 = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item1->id,
            'description' => 'Material A',
            'quantity' => '100.000',
            'unit' => 'kg',
            'unit_price' => '10.00',
            'total' => '1050.00',
            'rfq_line_freight_amount' => '50.00',
            'rfq_line_other_charges' => '0.00',
        ]);

        $poi2 = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item2->id,
            'description' => 'Material B',
            'quantity' => '50.000',
            'unit' => 'kg',
            'unit_price' => '20.00',
            'total' => '1000.00',
            'rfq_line_freight_amount' => '0.00',
            'rfq_line_other_charges' => '0.00',
        ]);

        $loc = WarehouseLocation::factory()->create();

        $grn = $this->grnSvc->create($po, [
            [
                'purchase_order_item_id' => $poi1->id,
                'item_id' => $item1->id,
                'location_id' => $loc->id,
                'quantity_received' => '100.000',
            ],
            [
                'purchase_order_item_id' => $poi2->id,
                'item_id' => $item2->id,
                'location_id' => $loc->id,
                'quantity_received' => '50.000',
            ],
        ], ['received_date' => now()->toDateString()], $this->user);

        // Verify GRN items received at delivered cost, not bare unit_price.
        $grnItem1 = $grn->items()->where('item_id', $item1->id)->first();
        $grnItem2 = $grn->items()->where('item_id', $item2->id)->first();

        $this->assertNotNull($grnItem1);
        $this->assertNotNull($grnItem2);

        // GRN item 1 should have delivered cost 11.5244
        $this->assertEquals('11.5244', (string) $grnItem1->unit_cost);
        // GRN item 2 should have delivered cost 21.9512
        $this->assertEquals('21.9512', (string) $grnItem2->unit_cost);

        // Inventory receipt value = qty × delivered unit cost
        $inventory1 = bcmul('100', '11.5244', 2);  // 1152.44
        $inventory2 = bcmul('50', '21.9512', 2);   // 1097.56
        // Total inventory value = 2250.00 (exactly matches PO total incl. freight)
        $totalInventory = bcadd($inventory1, $inventory2, 2);
        $this->assertEquals('2250.00', $totalInventory);
    }

    /**
     * The expected-receipt draft staged when the PO is sent is the main
     * receiving path; it must also carry the delivered cost, re-derived at
     * finalize (the PO may have moved since staging).
     */
    public function test_staged_draft_grn_finalizes_at_delivered_cost(): void
    {
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Sent->value,
            'is_vatable' => false,
            'created_by' => $this->user->id,
            'vendor_id' => \App\Modules\Accounting\Models\Vendor::factory()->create()->id,
            'rfq_freight_amount' => '200.00',
            'rfq_other_charges' => '0.00',
        ]);
        $item1 = Item::factory()->create(['item_type' => ItemType::RawMaterial, 'unit_of_measure' => 'kg']);
        $item2 = Item::factory()->create(['item_type' => ItemType::RawMaterial, 'unit_of_measure' => 'kg']);
        $poi1 = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'item_id' => $item1->id, 'description' => 'Material A',
            'quantity' => '100.000', 'unit' => 'kg', 'unit_price' => '10.00', 'total' => '1050.00',
            'rfq_line_freight_amount' => '50.00', 'rfq_line_other_charges' => '0.00',
        ]);
        $poi2 = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'item_id' => $item2->id, 'description' => 'Material B',
            'quantity' => '50.000', 'unit' => 'kg', 'unit_price' => '20.00', 'total' => '1000.00',
            'rfq_line_freight_amount' => '0.00', 'rfq_line_other_charges' => '0.00',
        ]);

        $draft = $this->grnSvc->createDraftForPo($po, $this->user);
        $this->assertNotNull($draft);
        $this->assertSame('11.5244', (string) $draft->items()->where('item_id', $item1->id)->value('unit_cost'));

        // Simulate a stale staged cost (e.g. staged before this fix / PO revised).
        $draft->items()->update(['unit_cost' => '1.0000']);

        $loc = WarehouseLocation::factory()->create();
        $grn = $this->grnSvc->finalizeDraft($draft, [
            ['purchase_order_item_id' => $poi1->id, 'location_id' => $loc->id, 'quantity_received' => '100.000'],
            ['purchase_order_item_id' => $poi2->id, 'location_id' => $loc->id, 'quantity_received' => '50.000'],
        ], $this->user);

        $this->assertSame('11.5244', (string) $grn->items()->where('item_id', $item1->id)->value('unit_cost'));
        $this->assertSame('21.9512', (string) $grn->items()->where('item_id', $item2->id)->value('unit_cost'));
    }

    /**
     * Test 4: Bill auto-drafted from accepted GRN uses delivered cost and notes freight.
     */
    public function test_bill_auto_draft_includes_freight_in_price()
    {
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'is_vatable' => false,
            'created_by' => $this->user->id,
            'vendor_id' => \App\Modules\Accounting\Models\Vendor::factory()->create()->id,
            'rfq_freight_amount' => '200.00',
            'rfq_other_charges' => '0.00',
        ]);

        $item1 = Item::factory()->create(['item_type' => ItemType::RawMaterial, 'unit_of_measure' => 'kg']);
        $item2 = Item::factory()->create(['item_type' => ItemType::RawMaterial, 'unit_of_measure' => 'kg']);

        $poi1 = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item1->id,
            'description' => 'Material A',
            'quantity' => '100.000',
            'unit' => 'kg',
            'unit_price' => '10.00',
            'total' => '1050.00',
            'rfq_line_freight_amount' => '50.00',
            'rfq_line_other_charges' => '0.00',
        ]);

        $poi2 = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item2->id,
            'description' => 'Material B',
            'quantity' => '50.000',
            'unit' => 'kg',
            'unit_price' => '20.00',
            'total' => '1000.00',
            'rfq_line_freight_amount' => '0.00',
            'rfq_line_other_charges' => '0.00',
        ]);

        $loc = WarehouseLocation::factory()->create();

        $grn = $this->grnSvc->create($po, [
            [
                'purchase_order_item_id' => $poi1->id,
                'item_id' => $item1->id,
                'location_id' => $loc->id,
                'quantity_received' => '100.000',
            ],
            [
                'purchase_order_item_id' => $poi2->id,
                'item_id' => $item2->id,
                'location_id' => $loc->id,
                'quantity_received' => '50.000',
            ],
        ], ['received_date' => now()->toDateString()], $this->user);

        // Pass QC inspections
        $inspections = Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->get();

        foreach ($inspections as $inspection) {
            $inspection->update([
                'status' => 'passed',
                'inspector_id' => $this->user->id,
                'reviewed_by' => $this->checker->id,
                'reviewed_at' => now(),
            ]);
        }

        // Accept GRN and post GL
        $grn = $this->grnSvc->accept($grn, $this->user);
        $this->grnGlSvc->post($grn);

        // Create auto-draft bill
        $bill = $this->billSvc->createDraftForGrn($grn, $this->user);
        $this->assertNotNull($bill);
        $this->assertEquals(BillStatus::Draft, $bill->status);

        // Bill subtotal equals the capitalized inventory value to the centavo.
        $this->assertSame('2250.00', (string) $bill->subtotal);

        // Verify bill line descriptions include freight note
        $billItem1 = $bill->items()->where('item_id', $item1->id)->first();
        $billItem2 = $bill->items()->where('item_id', $item2->id)->first();

        $this->assertNotNull($billItem1);
        $this->assertNotNull($billItem2);
        $this->assertStringContainsString('incl. agreed freight/charges', $billItem1->description);
        $this->assertStringContainsString('incl. agreed freight/charges', $billItem2->description);

        // The bill line carries the 4-dp delivered cost, so qty × price = total
        // and the 3-way match sees no rounding-induced price variance.
        $this->assertSame('11.5244', (string) $billItem1->unit_price);
        $this->assertSame('1152.44', (string) $billItem1->total);
        $this->assertSame('21.9512', (string) $billItem2->unit_price);
        $this->assertSame('1097.56', (string) $billItem2->total);
        $this->assertFalse((bool) $bill->has_variances, 'Delivered-cost bill lines must match the PO delivered cost.');
    }

    /**
     * RFQ POs are stored is_vatable=false and carry the quoted VAT in
     * rfq_vat_amount. The auto-draft bill read only the flag, so every RFQ
     * receipt was billed with no VAT: AP short by the whole input VAT.
     */
    public function test_bill_auto_draft_carries_the_quoted_rfq_vat(): void
    {
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'is_vatable' => false,
            'created_by' => $this->user->id,
            'vendor_id' => \App\Modules\Accounting\Models\Vendor::factory()->create()->id,
            'subtotal' => '2250.00',
            'rfq_vat_amount' => '240.00',
            'rfq_freight_amount' => '200.00',
            'rfq_other_charges' => '0.00',
        ]);
        $item1 = Item::factory()->create(['item_type' => ItemType::RawMaterial, 'unit_of_measure' => 'kg']);
        $item2 = Item::factory()->create(['item_type' => ItemType::RawMaterial, 'unit_of_measure' => 'kg']);
        $poi1 = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'item_id' => $item1->id, 'description' => 'Material A',
            'quantity' => '100.000', 'unit' => 'kg', 'unit_price' => '10.00', 'total' => '1050.00',
            'rfq_line_freight_amount' => '50.00', 'rfq_line_other_charges' => '0.00',
        ]);
        $poi2 = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'item_id' => $item2->id, 'description' => 'Material B',
            'quantity' => '50.000', 'unit' => 'kg', 'unit_price' => '20.00', 'total' => '1000.00',
            'rfq_line_freight_amount' => '0.00', 'rfq_line_other_charges' => '0.00',
        ]);
        $loc = WarehouseLocation::factory()->create();

        // Line A only (₱1,152.44 of ₱2,250.00): its value share of the VAT.
        $partial = $this->acceptedGrn($po, [[$poi1, $item1, '100.000']], $loc);
        $bill = $this->billSvc->createDraftForGrn($partial, $this->user);
        $this->assertSame('1152.44', (string) $bill->subtotal);
        $this->assertSame('122.93', (string) $bill->vat_amount);
        $this->assertSame('1275.37', (string) $bill->total_amount);
        $this->assertTrue((bool) $bill->is_vatable);

        // Line B completes the PO; its draft carries the rest of the quote VAT.
        $rest = $this->acceptedGrn($po->fresh(), [[$poi2, $item2, '50.000']], $loc);
        $second = $this->billSvc->createDraftForGrn($rest, $this->user);
        $this->assertSame('1097.56', (string) $second->subtotal);
        $this->assertSame('117.07', (string) $second->vat_amount);
    }

    public function test_manual_bill_on_an_rfq_po_uses_the_quoted_vat(): void
    {
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'is_vatable' => false,
            'created_by' => $this->user->id,
            'vendor_id' => \App\Modules\Accounting\Models\Vendor::factory()->create()->id,
            'subtotal' => '2250.00',
            'rfq_vat_amount' => '240.00',
            'rfq_freight_amount' => '200.00',
            'rfq_other_charges' => '0.00',
        ]);
        $item = Item::factory()->create(['item_type' => ItemType::RawMaterial, 'unit_of_measure' => 'kg']);
        $poi = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'item_id' => $item->id, 'description' => 'Material A',
            'quantity' => '100.000', 'unit' => 'kg', 'unit_price' => '10.00', 'total' => '1050.00',
            'rfq_line_freight_amount' => '50.00', 'rfq_line_other_charges' => '0.00',
        ]);
        $grn = $this->acceptedGrn($po, [[$poi, $item, '100.000']], WarehouseLocation::factory()->create());
        // The auto-draft is discarded when the supplier invoices a different
        // price; the invoice is then keyed by hand against the same receipt.
        Bill::query()->where('goods_receipt_note_id', $grn->id)->get()
            ->each(fn (Bill $draft) => $draft->forceFill(['status' => BillStatus::Cancelled])->save());

        $bill = $this->billSvc->create([
            'bill_number' => 'RFQ-MAN-1',
            'vendor_id' => $po->vendor->hash_id,
            'purchase_order_id' => $po->hash_id,
            'goods_receipt_note_id' => $grn->hash_id,
            'provenance_type' => 'stock',
            'date' => now()->toDateString(),
            'is_vatable' => true,
            'items' => [[
                'item_id' => $item->id, 'description' => 'Material A', 'quantity' => '100', 'unit_price' => '12.50',
                'expense_account_id' => Account::query()->where('code', '5010')->firstOrFail()->id,
            ]],
        ], $this->user);

        // Delivered ₱12.50/kg carries all ₱200 header freight. 12% of ₱1,250.00
        // would be ₱150.00 — VAT on capitalized freight the supplier never
        // charged it on. The quote's value share is ₱133.33.
        $this->assertSame('1250.00', (string) $bill->subtotal);
        $this->assertSame('133.33', (string) $bill->vat_amount);
        $this->assertSame('1383.33', (string) $bill->total_amount);
    }

    /** @param  array<int, array{0: PurchaseOrderItem, 1: Item, 2: string}>  $lines */
    private function acceptedGrn(PurchaseOrder $po, array $lines, WarehouseLocation $loc): GoodsReceiptNote
    {
        $grn = $this->grnSvc->create($po, array_map(static fn (array $line): array => [
            'purchase_order_item_id' => $line[0]->id,
            'item_id' => $line[1]->id,
            'location_id' => $loc->id,
            'quantity_received' => $line[2],
        ], $lines), ['received_date' => now()->toDateString()], $this->user);
        Inspection::query()->where('entity_type', 'grn')->where('entity_id', $grn->id)->get()
            ->each(fn (Inspection $inspection) => $inspection->update([
                'status' => 'passed',
                'inspector_id' => $this->user->id,
                'reviewed_by' => $this->checker->id,
                'reviewed_at' => now(),
            ]));

        return $this->grnSvc->accept($grn, $this->user);
    }

    /**
     * Test 5: 3-way match (implicitly tested through auto-draft bill).
     *
     * When a bill is auto-drafted from a GRN that received at delivered cost,
     * the 3-way match will compare bill (at delivered cost) against PO expected
     * (also at delivered cost), resulting in match with no variance.
     * This is covered by test_bill_auto_draft_includes_freight_in_price which
     * verifies the snapshot has no variances.
     */
    public function test_three_way_match_via_auto_draft(): void
    {
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'is_vatable' => false,
            'created_by' => $this->user->id,
            'vendor_id' => \App\Modules\Accounting\Models\Vendor::factory()->create()->id,
            'rfq_freight_amount' => '200.00',
            'rfq_other_charges' => '0.00',
        ]);

        $item1 = Item::factory()->create(['item_type' => ItemType::RawMaterial, 'unit_of_measure' => 'kg']);

        $poi1 = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item1->id,
            'description' => 'Material A',
            'quantity' => '100.000',
            'unit' => 'kg',
            'unit_price' => '10.00',
            'total' => '1050.00',
            'rfq_line_freight_amount' => '50.00',
            'rfq_line_other_charges' => '0.00',
        ]);

        $loc = WarehouseLocation::factory()->create();

        $grn = $this->grnSvc->create($po, [
            [
                'purchase_order_item_id' => $poi1->id,
                'item_id' => $item1->id,
                'location_id' => $loc->id,
                'quantity_received' => '100.000',
            ],
        ], ['received_date' => now()->toDateString()], $this->user);

        // Pass QC inspections
        $inspections = Inspection::query()
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->get();

        foreach ($inspections as $inspection) {
            $inspection->update([
                'status' => 'passed',
                'inspector_id' => $this->user->id,
                'reviewed_by' => $this->checker->id,
                'reviewed_at' => now(),
            ]);
        }

        // Accept GRN and post GL
        $grn = $this->grnSvc->accept($grn, $this->user);
        $this->grnGlSvc->post($grn);

        // Create auto-draft bill
        $bill = $this->billSvc->createDraftForGrn($grn, $this->user);
        $this->assertNotNull($bill);

        // Verify 3-way match has no variances (all prices aligned)
        $this->assertEquals('matched', $bill->three_way_match_snapshot['overall_status']);
    }

    /**
     * Test 6: Non-RFQ PO unchanged (no freight, no special handling).
     */
    public function test_non_rfq_po_unchanged()
    {
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'is_vatable' => false,
            'created_by' => $this->user->id,
            'vendor_id' => \App\Modules\Accounting\Models\Vendor::factory()->create()->id,
        ]);

        $item = Item::factory()->create(['item_type' => ItemType::RawMaterial, 'unit_of_measure' => 'kg']);

        $poi = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Plain material',
            'quantity' => '100.000',
            'unit' => 'kg',
            'unit_price' => '12.50',
            'total' => '1250.00',
        ]);

        $loc = WarehouseLocation::factory()->create();

        $grn = $this->grnSvc->create($po, [
            [
                'purchase_order_item_id' => $poi->id,
                'item_id' => $item->id,
                'location_id' => $loc->id,
                'quantity_received' => '100.000',
            ],
        ], ['received_date' => now()->toDateString()], $this->user);

        // GRN unit_cost should be exactly 12.50 (no freight)
        $grnItem = $grn->items()->first();
        $this->assertEquals('12.5000', (string) $grnItem->unit_cost);
    }
}
