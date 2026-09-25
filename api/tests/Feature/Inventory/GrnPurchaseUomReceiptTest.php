<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemUomConversion;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Models\Inspection;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class GrnPurchaseUomReceiptTest extends TestCase
{
    use RefreshDatabase;

    private User $receiver;

    private User $checker;

    private GrnService $service;

    private Item $item;

    private WarehouseLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([StockMovementCompleted::class]);
        $this->receiver = User::factory()->create(['is_active' => true]);
        $this->checker = User::factory()->create(['is_active' => true]);
        $this->service = app(GrnService::class);
        $this->item = Item::factory()->create(['unit_of_measure' => 'KG', 'is_active' => true]);
        $this->location = WarehouseLocation::factory()->create();

        $kg = Uom::create(['code' => 'KG', 'name' => 'Kilogram']);
        $bag = Uom::create(['code' => 'BAG', 'name' => 'Bag']);
        ItemUomConversion::create([
            'item_id' => $this->item->id,
            'from_uom_id' => $bag->id,
            'to_uom_id' => $kg->id,
            'factor' => '5.000000',
        ]);
    }

    /** @return array{PurchaseOrder, PurchaseOrderItem} */
    private function order(PurchaseOrderStatus $status = PurchaseOrderStatus::Approved): array
    {
        $po = PurchaseOrder::factory()->create([
            'status' => $status,
            'created_by' => $this->receiver->id,
        ]);
        $line = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $this->item->id,
            'description' => 'Resin',
            'quantity' => '2',
            'unit' => 'BAG',
            'unit_price' => '25.00',
            'total' => '50.00',
        ]);

        return [$po, $line];
    }

    private function receive(PurchaseOrder $po, PurchaseOrderItem $line, string $quantity, ?string $uom = null, ?string $unitCost = null): GoodsReceiptNote
    {
        return $this->service->create($po, [[
            'purchase_order_item_id' => $line->id,
            'item_id' => $this->item->id,
            'location_id' => $this->location->hash_id,
            'quantity_received' => $quantity,
            'received_uom_code' => $uom,
            'unit_cost' => $unitCost,
        ]], [], $this->receiver);
    }

    private function passAndAccept(GoodsReceiptNote $grn): void
    {
        Inspection::query()->where('entity_type', 'grn')->where('entity_id', $grn->id)
            ->update(['status' => 'passed', 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()]);
        $this->service->accept($grn->fresh(), $this->receiver);
    }

    private function assertOneBagValue(GoodsReceiptNote $grn): void
    {
        $this->passAndAccept($grn);

        $stock = StockLevel::query()->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)->firstOrFail();
        $movement = StockMovement::query()->where('reference_type', 'goods_receipt_note')
            ->where('reference_id', $grn->id)->firstOrFail();
        $this->assertSame('5.000', (string) $stock->quantity);
        $this->assertSame('5.0000', (string) $stock->weighted_avg_cost);
        $this->assertSame('5.0000', (string) $movement->unit_cost);
        $this->assertSame('25.00', (string) $movement->total_cost);
        $this->assertSame('5.0000', (string) $grn->items()->firstOrFail()->unit_cost);

        $grni = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.reference_type', 'goods_receipt_note')
            ->where('e.reference_id', $grn->id)
            ->where('a.code', '2110')
            ->value('l.credit');
        $this->assertSame('25.00', (string) $grni);
    }

    public function test_direct_receipt_prices_five_base_kg_at_one_25_peso_bag_after_qc(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        app(SettingsService::class)->set('modules.accounting', true, 'modules');
        [$po, $line] = $this->order();

        // The optional echoed price is still expressed per PO purchase unit.
        $grn = $this->receive($po, $line, '5.000', 'KG', '25.00');

        $this->assertOneBagValue($grn);
    }

    public function test_draft_receipt_reprices_purchase_units_as_base_kg_before_acceptance(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        app(SettingsService::class)->set('modules.accounting', true, 'modules');
        [$po, $line] = $this->order(PurchaseOrderStatus::Sent);
        $draft = $this->service->createDraftForPo($po, $this->receiver);
        $this->assertNotNull($draft);
        // A zero-quantity expectation carries the purchase-unit price until finalization.
        $this->assertSame('25.0000', (string) $draft->items()->firstOrFail()->unit_cost);

        $grn = $this->service->finalizeDraft($draft, [[
            'purchase_order_item_id' => $line->id,
            'location_id' => $this->location->hash_id,
            'quantity_received' => '1',
            'received_uom_code' => 'BAG',
        ]], $this->receiver);

        $this->assertSame('5.000', (string) $grn->items()->firstOrFail()->quantity_received);
        $this->assertOneBagValue($grn);
    }

    public function test_direct_receipt_still_rejects_a_client_supplied_price_other_than_the_po_price(): void
    {
        [$po, $line] = $this->order();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('receipt cost is fixed at the PO price (25.00)');
        $this->receive($po, $line, '5.000', 'KG', '5.00');
    }

    public function test_rfq_delivered_cost_echo_remains_valid_and_is_converted_from_bag_to_kg(): void
    {
        [$po, $line] = $this->order();
        $line->update(['rfq_line_freight_amount' => '10.00']);

        // ₱50 goods + ₱10 agreed freight over two bags = ₱30/BAG = ₱6/KG.
        $this->assertSame('30.0000', $line->fresh()->load('purchaseOrder.items')->deliveredUnitCost());
        $grn = $this->receive($po, $line, '5.000', 'KG', '30.0000');

        $this->assertSame('6.0000', (string) $grn->items()->firstOrFail()->unit_cost);
    }

    public function test_finalizing_a_draft_reprices_after_the_purchase_order_freight_changes(): void
    {
        [$po, $line] = $this->order(PurchaseOrderStatus::Sent);
        $draft = $this->service->createDraftForPo($po, $this->receiver);
        $this->assertNotNull($draft);
        $this->assertSame('25.0000', (string) $draft->items()->firstOrFail()->unit_cost);

        $line->update(['rfq_line_freight_amount' => '10.00']);
        $grn = $this->service->finalizeDraft($draft, [[
            'purchase_order_item_id' => $line->id,
            'location_id' => $this->location->hash_id,
            'quantity_received' => '5.000',
        ]], $this->receiver);

        $this->assertSame('6.0000', (string) $grn->items()->firstOrFail()->unit_cost);
    }

    public function test_base_unit_receipts_fill_a_purchase_uom_po_only_after_qc_accepts_the_full_base_order(): void
    {
        [$po, $line] = $this->order();

        $first = $this->receive($po, $line, '5.000');
        $this->assertSame('5.00', (string) $line->fresh()->quantity_received);
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $po->fresh()->status);
        $this->passAndAccept($first);
        $this->assertSame('5.000', (string) $line->fresh()->quantity_accepted);
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $po->fresh()->status);

        $second = $this->receive($po->fresh(), $line, '5.000');
        $this->assertSame('10.00', (string) $line->fresh()->quantity_received);
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $po->fresh()->status);
        $this->passAndAccept($second);
        $this->assertSame('10.000', (string) $line->fresh()->quantity_accepted);
        $this->assertSame(PurchaseOrderStatus::Received, $po->fresh()->status);
    }

    public function test_finalizing_a_draft_in_purchase_uom_uses_base_quantity_for_po_status(): void
    {
        [$po, $line] = $this->order(PurchaseOrderStatus::Sent);
        $draft = $this->service->createDraftForPo($po, $this->receiver);
        $this->assertNotNull($draft);

        $grn = $this->service->finalizeDraft($draft, [[
            'purchase_order_item_id' => $line->id,
            'location_id' => $this->location->hash_id,
            'quantity_received' => '1',
            'received_uom_code' => 'BAG',
        ]], $this->receiver);

        $this->assertSame('5.000', (string) $grn->items()->firstOrFail()->quantity_received);
        $this->assertSame('5.00', (string) $line->fresh()->quantity_received);
        $this->passAndAccept($grn);
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $po->fresh()->status);
    }

    public function test_over_receipt_tolerance_is_a_percentage_of_the_base_order(): void
    {
        [$po, $line] = $this->order();
        app(SettingsService::class)->set('inventory.over_receipt_tolerance_pct', 10);

        $this->receive($po, $line, '11.000'); // 2 BAG = 10 KG + 10% = 11 KG
        $this->assertSame('11.00', (string) $line->fresh()->quantity_received);

        [$otherPo, $otherLine] = $this->order();
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('max 11.000');
        $this->receive($otherPo, $otherLine, '11.001');
    }

    public function test_rejecting_unaccepted_base_quantity_reopens_only_the_unfilled_part_of_the_purchase_order(): void
    {
        [$po, $line] = $this->order();
        $grn = $this->receive($po, $line, '10.000');
        Inspection::query()->where('entity_type', 'grn')->where('entity_id', $grn->id)
            ->update(['status' => 'passed', 'reviewed_by' => $this->checker->id, 'reviewed_at' => now()]);

        $this->service->partialAccept($grn, [$grn->items()->firstOrFail()->id => '5.000'], $this->receiver);
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $po->fresh()->status);

        $this->service->rejectRemainder($grn->fresh(), 'Remaining material failed incoming inspection', $this->receiver);
        $this->assertSame('5.00', (string) $line->fresh()->quantity_received);
        $this->assertSame('5.000', (string) $line->fresh()->quantity_accepted);
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $po->fresh()->status);

        $replacement = $this->receive($po->fresh(), $line, '5.000');
        $this->passAndAccept($replacement);
        $this->assertSame(PurchaseOrderStatus::Received, $po->fresh()->status);
    }
}
