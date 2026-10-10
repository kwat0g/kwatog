<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Policies\SupplierPoCapabilities;
use App\Modules\B2B\Resources\SupplierPurchaseOrderResource;
use App\Modules\B2B\Services\SupplierDeliveryScheduleService;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Enums\WarehouseZoneType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemUomConversion;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\MaterialIssueSlipItem;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Resources\ReceivablePurchaseOrderResource;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Inventory\Services\PickingListService;
use App\Modules\Inventory\Services\QuarantineService;
use App\Modules\Inventory\Services\StockLocationSummaryService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Services\StockTransferService;
use App\Modules\Inventory\Services\TransferOrderService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Regression coverage for purchasing quantities, quarantine, and lot operations. */
class InventoryOperationsSafetyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        app(SettingsService::class)->set('modules.inventory', true, 'modules');
        app(SettingsService::class)->set('modules.accounting', false, 'modules');
        app(SettingsService::class)->set('inventory.over_receipt_tolerance_pct', 0);
    }

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::factory()->create(['role_id' => Role::query()->where('slug', 'system_admin')->value('id'), 'is_active' => true]);
    }

    private function order(Item $item, User $user, string $quantity, string $unit): array
    {
        $po = PurchaseOrder::factory()->create(['status' => PurchaseOrderStatus::Approved, 'created_by' => $user->id]);
        $line = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'item_id' => $item->id,
            'description' => $item->name, 'quantity' => $quantity, 'unit' => $unit,
            'unit_price' => '25.00', 'total' => bcmul($quantity, '25', 2),
        ]);

        return [$po, $line];
    }

    public function test_conversion_change_is_blocked_for_approved_order(): void
    {
        $admin = $this->admin();
        $kg = Uom::firstOrCreate(['code' => 'AUDKG'], ['name' => 'Audit Kilogram']);
        $bag = Uom::firstOrCreate(['code' => 'AUDBAG'], ['name' => 'Audit Bag']);
        $item = Item::factory()->create(['unit_of_measure' => 'AUDKG']);
        $conversion = ItemUomConversion::create(['item_id' => $item->id, 'from_uom_id' => $bag->id, 'to_uom_id' => $kg->id, 'factor' => '5.000000']);
        [$po, $line] = $this->order($item, $admin, '2', 'AUDBAG');
        $this->assertSame('10.000000', $item->convertToBase('2', 'AUDBAG'));

        $this->actingAs($admin)->postJson("/api/v1/inventory/items/{$item->hash_id}/uom-conversions", [
            'from_uom_id' => $bag->hash_id, 'to_uom_id' => $kg->hash_id, 'factor' => '50.000000',
        ])->assertStatus(422);
        $this->assertSame('5.000000', $conversion->fresh()->factor);
        $po->forceFill(['status' => PurchaseOrderStatus::Received])->save();
        $this->actingAs($admin)->deleteJson("/api/v1/inventory/items/{$item->hash_id}/uom-conversions/{$conversion->hash_id}")->assertStatus(422);

    }

    public function test_open_order_blocks_conversion_deletion(): void
    {
        $admin = $this->admin();
        $kg = Uom::firstOrCreate(['code' => 'AUDKG'], ['name' => 'Audit Kilogram']);
        $bag = Uom::firstOrCreate(['code' => 'AUDBAG'], ['name' => 'Audit Bag']);
        $item = Item::factory()->create(['unit_of_measure' => 'AUDKG']);
        $conversion = ItemUomConversion::create(['item_id' => $item->id, 'from_uom_id' => $bag->id, 'to_uom_id' => $kg->id, 'factor' => '5.000000']);
        $this->order($item, $admin, '2', 'AUDBAG');
        $this->actingAs($admin)->deleteJson("/api/v1/inventory/items/{$item->hash_id}/uom-conversions/{$conversion->hash_id}")->assertStatus(422);
        $this->assertSame('10.000000', $item->fresh()->convertToBase('2', 'AUDBAG'));
    }

    public function test_safeguard_po_receipt_precision_matches_grn_totals(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create(['unit_of_measure' => 'kg']);
        [$po, $line] = $this->order($item, $user, '100', $item->unit_of_measure);
        $location = WarehouseLocation::factory()->create();
        for ($i = 0; $i < 4; $i++) {
            app(GrnService::class)->create($po->fresh(), [[
                'purchase_order_item_id' => $line->id, 'item_id' => $item->id,
                'location_id' => $location->hash_id, 'quantity_received' => '10.005',
            ]], [], $user);
        }
        $grnTotal = DB::table('grn_items')->where('purchase_order_item_id', $line->id)->sum('quantity_received');
        $this->assertSame('40.020', bcadd((string) $grnTotal, '0', 3));
        $this->assertSame('40.02', (string) $line->fresh()->quantity_received);
    }

    public function test_transfer_order_cannot_remove_held_quarantine_stock_without_mrb_release(): void
    {
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $item = Item::factory()->create();
        $good = WarehouseLocation::factory()->create();
        $quarantineZone = WarehouseZone::factory()->create(['warehouse_id' => $good->zone->warehouse_id, 'zone_type' => WarehouseZoneType::Quarantine]);
        $quarantine = WarehouseLocation::factory()->create(['zone_id' => $quarantineZone->id]);
        app(StockMovementService::class)->move(new StockMovementInput(
            type: StockMovementType::GrnReceipt, itemId: $item->id, toLocationId: $good->id,
            quantity: '5.000', unitCost: '1.0000', lotNumber: 'AUD-MRB-LOT',
        ));
        $hold = app(QuarantineService::class)->hold([
            'item_id' => $item->id, 'quantity' => '5.000', 'source_location_id' => $good->id,
            'quarantine_location_id' => $quarantine->id, 'lot_number' => 'AUD-MRB-LOT',
        ], $maker);
        $order = app(TransferOrderService::class)->create([
            'item_id' => $item->id, 'from_location_id' => $quarantine->id,
            'to_location_id' => $good->id, 'quantity' => '5.000',
        ], $maker);
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('MRB');
        app(TransferOrderService::class)->execute($order->id, $checker);
    }

    public function test_receivable_resource_exposes_base_quantities(): void
    {
        $user = User::factory()->create();
        $kg = Uom::firstOrCreate(['code' => 'AUDKG'], ['name' => 'Audit Kilogram']);
        $bag = Uom::firstOrCreate(['code' => 'AUDBAG'], ['name' => 'Audit Bag']);
        $item = Item::factory()->create(['unit_of_measure' => 'AUDKG']);
        ItemUomConversion::create(['item_id' => $item->id, 'from_uom_id' => $bag->id, 'to_uom_id' => $kg->id, 'factor' => '5.000000']);
        [$po, $line] = $this->order($item, $user, '2', 'AUDBAG');
        $payload = (new ReceivablePurchaseOrderResource(app(GrnService::class)->receivablePurchaseOrder($po)))->response()->getData(true);
        $this->assertSame('10.000', $payload['data']['items'][0]['base_quantity_remaining']);
        $this->assertSame('10.00', $payload['data']['items'][0]['quantity_remaining']);
        $this->assertSame('AUDKG', $payload['data']['items'][0]['item']['unit_of_measure']);
        $line->update(['quantity_received' => '5.000', 'quantity_accepted' => '5.000']);
        $payload = (new ReceivablePurchaseOrderResource(app(GrnService::class)->receivablePurchaseOrder($po->fresh())))->response()->getData(true);
        $this->assertSame('5.000', $payload['data']['items'][0]['base_quantity_remaining']);
        $this->assertSame('5.00', $payload['data']['items'][0]['quantity_remaining']);
        $this->assertSame(PurchaseOrderStatus::Approved, $po->fresh()->status);
        $po->forceFill(['status' => PurchaseOrderStatus::PartiallyReceived])->save();
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $po->fresh()->receiptStatusOr(PurchaseOrderStatus::Approved));
        $this->assertSame(50.0, $po->fresh()->quantity_received_percent);
        $this->assertSame(50.0, $po->fresh()->quantity_accepted_percent);
        $this->assertTrue(SupplierPoCapabilities::hasOpenQuantity($po->fresh()));
        $this->assertSame('1.00', SupplierPoCapabilities::schedulableQuantities($po->fresh())[$line->id]);
        $supplier = (new SupplierPurchaseOrderResource($po->fresh()->load('items.item')))->response()->getData(true);
        $this->assertSame('1.00', $supplier['data']['items'][0]['quantity_received']);
        $this->assertSame('1.00', $supplier['data']['items'][0]['quantity_remaining']);
        $options = app(SupplierDeliveryScheduleService::class)->schedulablePurchaseOrders($po->vendor_id);
        $option = collect($options)->firstWhere('id', $po->hash_id);
        $this->assertNotNull($option);
        $this->assertSame('1.00', $option['items'][0]['quantity_received']);
        $conversion = ItemUomConversion::where('item_id', $item->id)->firstOrFail();
        $conversion->update(['factor' => '1000.000000']);
        $line->update(['quantity' => '1', 'quantity_received' => '1.000']);
        $this->assertSame('0.99', SupplierPoCapabilities::schedulableQuantities($po->fresh())[$line->id]);
        $conversion->update(['factor' => '5.000000']);
        $line->update(['quantity' => '2', 'quantity_received' => '5.000']);

        // The remaining shipment is visible in base units after partial acceptance.
        $this->assertSame('10.000000', $item->convertToBase((string) $line->quantity, $line->unit));
    }

    public function test_mixed_lot_transfer_preserves_split_lots(): void
    {
        $item = Item::factory()->create();
        $source = WarehouseLocation::factory()->create();
        $destination = WarehouseLocation::factory()->create();
        foreach (['AUD-LOT-A', 'AUD-LOT-B'] as $lot) {
            app(StockMovementService::class)->move(new StockMovementInput(
                type: StockMovementType::GrnReceipt, itemId: $item->id, toLocationId: $source->id,
                quantity: '5.000', unitCost: '1.0000', lotNumber: $lot,
            ));
        }
        $this->assertSame('10.000', (string) StockLevel::where('item_id', $item->id)->where('location_id', $source->id)->value('quantity'));
        app(StockTransferService::class)->transfer($item->id, $source->id, $destination->id, '6.000', null, User::factory()->create());
        $this->assertSame('6.000', (string) StockLevel::where('item_id', $item->id)->where('location_id', $destination->id)->value('quantity'));

    }

    public function test_picking_suggestion_is_capped_by_lot_quantity(): void
    {
        $item = Item::factory()->create();
        $source = WarehouseLocation::factory()->create();
        foreach (['AUD-LOT-A', 'AUD-LOT-B'] as $lot) {
            app(StockMovementService::class)->move(new StockMovementInput(
                type: StockMovementType::GrnReceipt, itemId: $item->id, toLocationId: $source->id,
                quantity: '5.000', unitCost: '1.0000', lotNumber: $lot,
            ));
        }
        $slip = MaterialIssueSlip::create(['slip_number' => 'PICK-'.uniqid(), 'issued_date' => now()->toDateString(), 'status' => 'issued', 'total_value' => '6.00', 'issued_by' => User::factory()->create()->id, 'created_by' => User::factory()->create()->id]);
        MaterialIssueSlipItem::create(['material_issue_slip_id' => $slip->id, 'item_id' => $item->id, 'location_id' => $source->id, 'quantity_issued' => '6.000', 'unit_cost' => '1.0000', 'total_cost' => '6.00']);
        $suggestions = app(PickingListService::class)->generateForMis($slip->id)['lines'][0]['suggestions'];
        $pick = $suggestions[0];
        $this->assertSame('AUD-LOT-A', $pick['lot_number']);
        $this->assertSame('5.000', $pick['quantity_to_pick']);
        $this->assertSame('5.000', $pick['quantity_available']);
        $this->assertSame('1.000', $suggestions[1]['quantity_to_pick']);
        $this->assertSame('AUD-LOT-B', $suggestions[1]['lot_number']);
        StockLevel::where('item_id', $item->id)->where('location_id', $source->id)->update(['reserved_quantity' => '6.000']);
        $restricted = app(PickingListService::class)->generateForMis($slip->id)['lines'][0]['suggestions'];
        $this->assertSame('4.000', array_reduce($restricted, fn ($sum, $row) => bcadd($sum, $row['quantity_to_pick'], 3), '0.000'));
        $this->assertSame('5.000', app(StockLocationSummaryService::class)->lotQuantity($item->id, $source->id, $pick['lot_number']));
    }

    public function test_safeguard_direct_quarantine_material_issue_is_blocked(): void
    {
        $item = Item::factory()->create();
        $zone = WarehouseZone::factory()->create(['zone_type' => WarehouseZoneType::Quarantine]);
        $location = WarehouseLocation::factory()->create(['zone_id' => $zone->id]);
        app(StockMovementService::class)->move(new StockMovementInput(type: StockMovementType::Opening, itemId: $item->id, toLocationId: $location->id, quantity: '1.000', unitCost: '1.0000'));
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('quarantine');
        app(StockMovementService::class)->move(new StockMovementInput(type: StockMovementType::MaterialIssue, itemId: $item->id, fromLocationId: $location->id, quantity: '1.000'));
    }
}
