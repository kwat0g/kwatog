<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Services\InspectionService;
use App\Modules\SupplyChain\Enums\ShipmentStatus;
use App\Modules\SupplyChain\Models\Shipment;
use App\Modules\SupplyChain\Services\LandedCostService;
use App\Modules\SupplyChain\Services\ShipmentService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class LandedCostCapitalizationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([StockMovementCompleted::class]);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->user = User::factory()->create();
        app(SettingsService::class)->set('modules.accounting', true, 'modules');
    }

    public function test_accepted_grn_capitalizes_landed_cost_into_wac_and_posts_to_clearing(): void
    {
        [$shipment, $grn, $item, $location] = $this->arrange(quantity: '100.000', landedCost: '100.00');
        app(LandedCostService::class)->calculate($shipment);

        $accepted = app(GrnService::class)->accept($grn, $this->user);
        $line = $accepted->items()->firstOrFail();
        $level = StockLevel::query()->where('item_id', $item->id)->where('location_id', $location->id)->firstOrFail();
        $entry = DB::table('journal_entries')->where('id', $accepted->journal_entry_id)->firstOrFail();

        $this->assertSame('1.0000', (string) $line->landed_cost_unit);
        $this->assertSame('100.00', (string) $line->landed_cost_total);
        $this->assertSame('11.0000', (string) $level->weighted_avg_cost);
        $this->assertSame('1100.00', (string) $entry->total_debit);
        $this->assertSame('1100.00', (string) $entry->total_credit);
        $this->assertSame('1000.00', (string) $this->lineAmount($entry->id, '2110', 'credit'));
        $this->assertSame('100.00', (string) $this->lineAmount($entry->id, '2120', 'credit'));
        $this->assertSame('1100.00', (string) $this->lineAmount($entry->id, '1200', 'debit'));
    }

    public function test_partial_acceptance_posts_landed_cost_only_for_the_new_delta(): void
    {
        [$shipment, $grn, $item, $location] = $this->arrange(quantity: '100.000', landedCost: '100.00');
        app(LandedCostService::class)->calculate($shipment);

        $line = $grn->items()->firstOrFail();
        $service = app(GrnService::class);
        $first = $service->partialAccept($grn, [$line->id => '40.000'], $this->user);
        $second = $service->partialAccept($first->fresh(), [$line->id => '100.000'], $this->user);

        $entries = DB::table('journal_entries')
            ->where('reference_type', 'goods_receipt_note')
            ->where('reference_id', $grn->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $entries);
        $this->assertSame('440.00', (string) $entries[0]->total_debit);
        $this->assertSame('660.00', (string) $entries[1]->total_debit);
        $this->assertSame('400.00', (string) $this->lineAmount($entries[0]->id, '2110', 'credit'));
        $this->assertSame('40.00', (string) $this->lineAmount($entries[0]->id, '2120', 'credit'));
        $this->assertSame('600.00', (string) $this->lineAmount($entries[1]->id, '2110', 'credit'));
        $this->assertSame('60.00', (string) $this->lineAmount($entries[1]->id, '2120', 'credit'));
        $this->assertSame('11.0000', (string) StockLevel::query()
            ->where('item_id', $item->id)
            ->where('location_id', $location->id)
            ->value('weighted_avg_cost'));
        $this->assertSame(GrnStatus::Accepted, $second->status);
    }

    public function test_landed_cost_cannot_be_recalculated_after_acceptance(): void
    {
        [$shipment, $grn] = $this->arrange(quantity: '10.000', landedCost: '10.00');
        $landed = app(LandedCostService::class);
        $landed->calculate($shipment);
        app(GrnService::class)->accept($grn, $this->user);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('after this shipment has an accepted receipt');
        $landed->recalculate($shipment->fresh());
    }

    public function test_landed_cost_inputs_cannot_be_changed_after_acceptance(): void
    {
        [$shipment, $grn] = $this->arrange(quantity: '10.000', landedCost: '10.00');
        app(LandedCostService::class)->calculate($shipment);
        app(GrnService::class)->accept($grn, $this->user);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('cannot be changed after this shipment has an accepted receipt');
        app(ShipmentService::class)->updateMeta($shipment->fresh(), ['freight_cost' => '25.00']);
    }

    public function test_landed_cost_bill_clears_the_capitalization_liability(): void
    {
        [$shipment, $grn] = $this->arrange(quantity: '10.000', landedCost: '10.00');
        app(LandedCostService::class)->calculate($shipment);
        app(GrnService::class)->accept($grn, $this->user);

        $po = PurchaseOrder::query()->findOrFail($shipment->purchase_order_id);
        $clearingId = (int) DB::table('accounts')->where('code', '2120')->value('id');
        $bill = app(BillService::class)->create([
            'bill_number' => 'BILL-LC-'.substr(uniqid(), -6),
            'vendor_id' => app('hashids')->encode($po->vendor_id),
            'provenance_type' => 'landed_cost',
            'landed_cost_shipment_id' => $shipment->hash_id,
            'date' => now()->toDateString(),
            'is_vatable' => false,
            'items' => [[
                'expense_account_id' => app('hashids')->encode($clearingId),
                'description' => 'Freight invoice',
                'quantity' => '1',
                'unit_price' => '10.00',
            ]],
        ], $this->user);

        $entry = DB::table('journal_entries')->where('id', $bill->journal_entry_id)->firstOrFail();
        $this->assertSame('10.00', (string) $this->lineAmount($entry->id, '2120', 'debit'));
        $this->assertSame('10.00', (string) $this->lineAmount($entry->id, '2010', 'credit'));
    }

    /** @return array{0: Shipment, 1: GoodsReceiptNote, 2: Item, 3: WarehouseLocation} */
    private function arrange(string $quantity, string $landedCost): array
    {
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'created_by' => $this->user->id,
        ]);
        // Imported resin is ordered in kg; the default factory item uses pcs.
        $item = Item::factory()->create(['item_type' => ItemType::RawMaterial->value, 'unit_of_measure' => 'kg']);
        $location = WarehouseLocation::factory()->create();
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Imported resin',
            'quantity' => $quantity,
            'unit' => 'kg',
            'unit_price' => '10.00',
            'total' => bcmul($quantity, '10.00', 2),
            'quantity_received' => $quantity,
        ]);
        $shipment = Shipment::create([
            'shipment_number' => 'SHP-LC-'.substr(uniqid(), -6),
            'purchase_order_id' => $po->id,
            'status' => ShipmentStatus::Ordered->value,
            'freight_cost' => $landedCost,
            'created_by' => $this->user->id,
        ]);
        $grn = GoodsReceiptNote::create([
            'grn_number' => 'GRN-LC-'.substr(uniqid(), -6),
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'shipment_id' => $shipment->id,
            'received_date' => now()->toDateString(),
            'received_by' => $this->user->id,
            'status' => GrnStatus::PendingQc,
        ]);
        $line = GrnItem::create([
            'goods_receipt_note_id' => $grn->id,
            'purchase_order_item_id' => $poItem->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_received' => $quantity,
            'quantity_accepted' => '0.000',
            'unit_cost' => '10.0000',
        ]);
        $inspector = User::factory()->create();
        $checker = User::factory()->create();
        $inspection = app(InspectionService::class)->createIncomingForItem($item, (int) $quantity, $grn->id, $inspector, null, $line->id);
        // Incoming GRN inspections require maker-checker review. Set both inspector_id and reviewed_by.
        $inspection->update([
            'status' => 'passed',
            'inspector_id' => $inspector->id,
            'reviewed_by' => $checker->id,
            'reviewed_at' => now(),
        ]);

        return [$shipment, $grn->fresh(['items']), $item, $location];
    }

    private function lineAmount(int $journalEntryId, string $accountCode, string $column): string
    {
        return (string) DB::table('journal_entry_lines as line')
            ->join('accounts as account', 'account.id', '=', 'line.account_id')
            ->where('line.journal_entry_id', $journalEntryId)
            ->where('account.code', $accountCode)
            ->value($column);
    }
}
