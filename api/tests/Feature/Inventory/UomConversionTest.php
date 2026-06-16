<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemUomConversion;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Inventory\Services\UomConversionService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * OGAMI-004 — multi-UOM conversion.
 *
 * Invariant: stock is stored in the item BASE uom (items.unit_of_measure).
 * Conversions translate an alternate purchase/issue uom into base at the edges.
 *
 *   factor = base units per ONE from-unit   (1 BAG = 25 KG → factor 25)
 */
class UomConversionTest extends TestCase
{
    use RefreshDatabase;

    private UomConversionService $svc;
    private Uom $kg;
    private Uom $bag;

    protected function setUp(): void
    {
        parent::setUp();

        // Auto-replenishment side-effect of receipts requires a system user
        // that does not exist in the isolated test DB — suppress it.
        Event::fake([StockMovementCompleted::class]);

        $this->svc = app(UomConversionService::class);
        $this->kg  = Uom::create(['code' => 'KG',  'name' => 'Kilogram']);
        $this->bag = Uom::create(['code' => 'BAG', 'name' => 'Bag']);
    }

    /** Item whose base uom is KG, with a 1 BAG = 25 KG conversion. */
    private function bagToKgItem(): Item
    {
        $item = Item::factory()->create(['unit_of_measure' => 'KG']);
        ItemUomConversion::create([
            'item_id'     => $item->id,
            'from_uom_id' => $this->bag->id,
            'to_uom_id'   => $this->kg->id,
            'factor'      => '25.000000',
        ]);
        return $item;
    }

    // ────────────────────────────────────────────────────────────────────────
    // 1. 1 BAG = 25 KG converts correctly
    // ────────────────────────────────────────────────────────────────────────

    public function test_converts_bags_to_base_kg(): void
    {
        $item = $this->bagToKgItem();

        // 3 BAG → 75 KG
        $this->assertSame('75.000000', $this->svc->toBase($item, '3', 'BAG'));

        // helper on the model returns the same result
        $this->assertSame('75.000000', $item->convertToBase('3', 'BAG'));
    }

    // ────────────────────────────────────────────────────────────────────────
    // 2. Identity when the supplied uom equals (or is null) the base uom
    // ────────────────────────────────────────────────────────────────────────

    public function test_identity_when_same_or_null_uom(): void
    {
        $item = $this->bagToKgItem();

        $this->assertSame('40.000000', $this->svc->toBase($item, '40', 'KG'));
        $this->assertSame('40.000000', $this->svc->toBase($item, '40', 'kg')); // case-insensitive
        $this->assertSame('40.000000', $this->svc->toBase($item, '40', null));
    }

    // ────────────────────────────────────────────────────────────────────────
    // 3. Missing conversion throws
    // ────────────────────────────────────────────────────────────────────────

    public function test_missing_conversion_throws(): void
    {
        // base KG, but no BAG → KG conversion configured
        $item = Item::factory()->create(['unit_of_measure' => 'KG']);

        $this->expectException(RuntimeException::class);

        $this->svc->toBase($item, '3', 'BAG');
    }

    // ────────────────────────────────────────────────────────────────────────
    // 4. Receiving in bags increments base stock in kg
    // ────────────────────────────────────────────────────────────────────────

    public function test_receiving_in_bags_increments_base_stock_in_kg(): void
    {
        $item     = $this->bagToKgItem();
        $location = WarehouseLocation::factory()->create();

        $role = Role::firstOrCreate(['slug' => 'warehouse_staff'], ['name' => 'Warehouse Staff']);
        $user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        // PO ordered in KG (PO-line uom capture is a follow-up; quantity treated
        // as base). 100 KG ordered.
        $po = PurchaseOrder::factory()->create([
            'status'     => PurchaseOrderStatus::Approved->value,
            'created_by' => $user->id,
        ]);
        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => $item->id,
            'description'       => 'Resin',
            'quantity'          => '100.000',
            'unit'              => 'KG',
            'unit_price'        => '10.00',
            'total'             => '1000.00',
            'quantity_received' => '0.000',
        ]);

        $grnSvc = app(GrnService::class);

        // Receive 2 BAG → must store 50 KG of base stock.
        $grn = $grnSvc->create(
            $po,
            [[
                'purchase_order_item_id' => $poItem->id,
                'item_id'                => $item->id,
                'location_id'            => $location->id,
                'quantity_received'      => '2',
                'received_uom_code'      => 'BAG',
                'unit_cost'              => '10.00',
            ]],
            ['received_date' => now()->toDateString()],
            $user,
        );

        // GRN line is stored in base (kg).
        $grnItem = $grn->items->first();
        $this->assertSame('50.000', number_format((float) $grnItem->quantity_received, 3, '.', ''));

        // PO running total advanced by base qty (kg).
        $poItem->refresh();
        $this->assertSame('50.000', number_format((float) $poItem->quantity_received, 3, '.', ''));

        // Accept the GRN → stock level must hold 50 KG.
        $grnSvc->accept($grn->fresh(), $user);

        $level = StockLevel::where('item_id', $item->id)
            ->where('location_id', $location->id)
            ->firstOrFail();

        $this->assertSame('50.000', $level->quantity, 'Receiving 2 BAG must add 50 KG of base stock');
    }
}
