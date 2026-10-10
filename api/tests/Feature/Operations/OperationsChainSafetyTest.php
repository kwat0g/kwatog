<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\AbcClassificationService;
use App\Modules\Inventory\Services\AutoReplenishmentService;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Inventory\Services\ItemService;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Services\InspectionService;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\Shipment;
use App\Modules\SupplyChain\Models\Vehicle;
use App\Modules\SupplyChain\Services\DriverDeliveryService;
use App\Modules\SupplyChain\Services\LandedCostService;
use App\Modules\SupplyChain\Services\ShipmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Regression coverage for dispatch ownership, landed costs, and replenishment. */
class OperationsChainSafetyTest extends TestCase
{
    use DatabaseTransactions;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        $this->seed(RolePermissionSeeder::class);
        $this->actor = User::factory()->withRole('system_admin')->create(['is_active' => true]);
        app(SettingsService::class)->set('modules.accounting', false);
        app(SettingsService::class)->set('modules.supply_chain', true);
    }

    private function delivery(): array
    {
        $driver = User::factory()->withRole('driver')->create(['is_active' => true]);
        $vehicle = Vehicle::create([
            'plate_number' => 'AUD-'.substr(uniqid(), -8), 'name' => 'Audit vehicle',
            'vehicle_type' => 'van', 'status' => 'available',
        ]);
        $delivery = Delivery::create([
            'delivery_number' => 'AUD-'.substr(uniqid(), -8),
            'sales_order_id' => SalesOrder::factory()->create(['status' => 'confirmed'])->id,
            'driver_id' => $driver->id, 'vehicle_id' => $vehicle->id,
            'status' => 'scheduled', 'scheduled_date' => today(), 'created_by' => $this->actor->id,
        ]);

        return [$delivery, $driver, $vehicle];
    }

    public function test_stale_driver_cannot_start_reassigned_delivery(): void
    {
        [$delivery, $driver] = $this->delivery();
        $other = User::factory()->withRole('driver')->create(['is_active' => true]);
        Delivery::whereKey($delivery->id)->update(['driver_id' => $other->id]);
        $this->expectException(HttpException::class);
        app(DriverDeliveryService::class)->updateStatus($driver, $delivery, 'loading');
    }

    public function test_stale_schedule_cannot_load_future_delivery(): void
    {
        [$delivery, $driver] = $this->delivery();
        Delivery::whereKey($delivery->id)->update(['scheduled_date' => today()->addDays(5)]);
        $this->expectException(ValidationException::class);
        app(DriverDeliveryService::class)->updateStatus($driver, $delivery, 'loading');
    }

    public function test_return_pending_vehicle_is_protected(): void
    {
        [$delivery, , $vehicle] = $this->delivery();
        $delivery->update(['status' => 'return_pending']);
        $vehicle->update(['status' => 'in_use']);
        $this->actingAs($this->actor)->patchJson('/api/v1/supply-chain/vehicles/'.$vehicle->hash_id, ['status' => 'available'])->assertStatus(422);
        $this->actingAs($this->actor)->deleteJson('/api/v1/supply-chain/vehicles/'.$vehicle->hash_id)->assertStatus(422);
        $this->assertFalse($vehicle->fresh()->trashed());
    }

    private function import(): array
    {
        $item = Item::factory()->create(['unit_of_measure' => 'kg', 'item_type' => 'raw_material']);
        $location = WarehouseLocation::factory()->create();
        $po = PurchaseOrder::factory()->create(['status' => 'approved', 'created_by' => $this->actor->id]);
        $poLine = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'item_id' => $item->id, 'description' => 'Audit resin',
            'quantity' => '10.000', 'unit' => 'kg', 'unit_price' => '10.00', 'total' => '100.00', 'quantity_received' => '10.000',
        ]);
        $shipment = Shipment::create([
            'shipment_number' => 'AUD-'.substr(uniqid(), -8), 'purchase_order_id' => $po->id,
            'status' => 'ordered', 'freight_cost' => '100.00', 'created_by' => $this->actor->id,
        ]);
        $grn = GoodsReceiptNote::create([
            'grn_number' => 'AUD-'.substr(uniqid(), -8), 'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id, 'shipment_id' => $shipment->id,
            'received_date' => today(), 'received_by' => $this->actor->id, 'status' => 'pending_qc',
        ]);
        $line = GrnItem::create([
            'goods_receipt_note_id' => $grn->id, 'purchase_order_item_id' => $poLine->id,
            'item_id' => $item->id, 'location_id' => $location->id, 'quantity_received' => '10.000',
            'quantity_accepted' => '0.000', 'unit_cost' => '10.0000',
        ]);
        $inspector = User::factory()->create();
        $checker = User::factory()->create();
        $inspection = app(InspectionService::class)->createIncomingForItem($item, 10, $grn->id, $inspector, null, $line->id);
        $inspection->update(['status' => 'passed', 'inspector_id' => $inspector->id, 'reviewed_by' => $checker->id, 'reviewed_at' => now()]);

        return [$shipment, $grn, $item, $location];
    }

    public function test_changed_import_cost_is_recalculated_before_acceptance(): void
    {
        [$shipment, $grn, $item, $location] = $this->import();
        app(LandedCostService::class)->calculate($shipment);
        app(ShipmentService::class)->updateMeta($shipment->fresh(), ['freight_cost' => '200.00']);
        $accepted = app(GrnService::class)->accept($grn, $this->actor);
        $this->assertSame('200.00', $shipment->fresh()->freight_cost);
        $this->assertSame('200.00', $accepted->items()->firstOrFail()->landed_cost_total);
        $this->assertSame('30.0000', StockLevel::where('item_id', $item->id)->where('location_id', $location->id)->value('weighted_avg_cost'));
        $this->expectException(BusinessRuleException::class);
        app(LandedCostService::class)->recalculate($shipment->fresh());
    }

    public function test_uncalculated_import_cost_is_applied_at_acceptance(): void
    {
        [$shipment, $grn] = $this->import();
        $accepted = app(GrnService::class)->accept($grn, $this->actor);
        $this->assertSame('100.00', $shipment->fresh()->freight_cost);
        $this->assertSame('100.00', $accepted->items()->firstOrFail()->landed_cost_total);
        $this->expectException(BusinessRuleException::class);
        app(LandedCostService::class)->calculate($shipment->fresh());
    }

    public function test_partial_shipment_allocates_all_cost_to_received_lines(): void
    {
        [$shipment, $grn] = $this->import();
        $otherItem = Item::factory()->create(['unit_of_measure' => 'kg', 'item_type' => 'raw_material']);
        $otherPoLine = PurchaseOrderItem::create([
            'purchase_order_id' => $shipment->purchase_order_id, 'item_id' => $otherItem->id,
            'description' => 'Not in this shipment', 'quantity' => '10', 'unit' => 'kg', 'unit_price' => '10', 'total' => '100',
        ]);
        app(LandedCostService::class)->calculate($shipment);
        $accepted = app(GrnService::class)->accept($grn->fresh(), $this->actor);
        $lines = $accepted->items()->orderBy('id')->get();
        $this->assertSame('100.00', $lines[0]->landed_cost_total);
        $this->assertCount(1, $lines);
        $this->assertSame('100.00', $shipment->fresh()->landed_cost_total);
        $this->assertNull($shipment->landedCosts()->where('purchase_order_item_id', $otherPoLine->id)->value('total_allocated'));
    }

    public function test_manual_allocation_ignores_value_and_splits_equally(): void
    {
        [$shipment, $grn] = $this->import();
        $grn->items()->delete();
        $grn->delete();
        PurchaseOrderItem::create([
            'purchase_order_id' => $shipment->purchase_order_id, 'item_id' => Item::factory()->create()->id,
            'description' => 'Second line', 'quantity' => '100', 'unit' => 'pcs', 'unit_price' => '9', 'total' => '900',
        ]);
        $changed = app(LandedCostService::class)->calculate($shipment, 'manual');
        $this->assertSame(['50.00', '50.00'], $changed->landedCosts->pluck('total_allocated')->all());
    }

    public function test_cent_allocations_are_nonnegative_and_reconcile(): void
    {
        $items = new Collection(array_fill(0, 10, new PurchaseOrderItem));
        $allocations = (new \ReflectionMethod(LandedCostService::class, 'allocate'))
            ->invoke(app(LandedCostService::class), $items, array_fill(0, 10, '0.100000000000'), '0.05');
        foreach ($allocations as $allocation) {
            $this->assertGreaterThanOrEqual(0, bccomp($allocation, '0', 2));
        }
        $this->assertSame('0.05', array_reduce($allocations, fn ($sum, $value) => bcadd($sum, $value, 2), '0.00'));
    }

    public function test_split_receipt_landed_costs_are_nonnegative_and_reconcile(): void
    {
        [$shipment, $grn] = $this->import();
        $shipment->update(['freight_cost' => '0.05']);
        $first = $grn->items()->firstOrFail();
        $first->update(['quantity_received' => '1.000']);
        for ($i = 0; $i < 9; $i++) {
            $grn->items()->create([
                'purchase_order_item_id' => $first->purchase_order_item_id, 'item_id' => $first->item_id,
                'location_id' => $first->location_id, 'quantity_received' => '1.000',
                'quantity_accepted' => '0.000', 'unit_cost' => '10.0000',
            ]);
        }
        app(LandedCostService::class)->calculate($shipment);
        $rows = $grn->items()->get();
        (new \ReflectionMethod(GrnService::class, 'snapshotLandedCosts'))->invoke(app(GrnService::class), $grn, $rows);
        $total = '0.00';
        foreach ($grn->items()->get() as $row) {
            $this->assertGreaterThanOrEqual(0, bccomp($row->landed_cost_total, '0', 2));
            $total = bcadd($total, $row->landed_cost_total, 2);
        }
        $this->assertSame('0.05', $total);
    }

    public function test_item_archive_protects_open_purchase_order(): void
    {
        $item = Item::factory()->create();
        $po = PurchaseOrder::factory()->create(['status' => 'approved', 'created_by' => $this->actor->id]);
        $line = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'item_id' => $item->id, 'description' => 'Open commitment',
            'quantity' => '10', 'unit' => $item->unit_of_measure, 'unit_price' => '10', 'total' => '100',
        ]);
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('purchasing');
        app(ItemService::class)->delete($item);
    }

    private function demand(Item $item, string $type): void
    {
        StockMovement::create([
            'item_id' => $item->id, 'movement_type' => $type, 'quantity' => '3000.000',
            'unit_cost' => '10.0000', 'total_cost' => '30000.00', 'created_at' => now()->subDay(),
            'created_by' => $this->actor->id,
        ]);
    }

    private function daysOfSupply(Item $item): string
    {
        app(SettingsService::class)->set('inventory.replenishment.demand_history_days', 30);
        app(SettingsService::class)->set('inventory.replenishment.coverage_buffer_ratio', 1.5);

        return (new \ReflectionMethod(AutoReplenishmentService::class, 'computeOrderQuantity'))
            ->invoke(app(AutoReplenishmentService::class), $item, '0.000');
    }

    public function test_days_of_supply_includes_delivery_demand(): void
    {
        $item = Item::factory()->create([
            'item_type' => 'finished_good', 'reorder_method' => 'days_of_supply',
            'reorder_point' => '10.000', 'minimum_order_quantity' => '0', 'lead_time_days' => 7,
        ]);
        $this->demand($item, 'delivery');
        $this->assertSame('1050.000', $this->daysOfSupply($item));
        $this->demand($item, 'material_issue');
        $this->assertSame('2100.000', $this->daysOfSupply($item));
    }

    public function test_days_of_supply_uses_default_lead_time(): void
    {
        app(SettingsService::class)->set('mrp.default_lead_time_days', 7);
        $item = Item::factory()->create([
            'reorder_method' => 'days_of_supply', 'reorder_point' => '10.000',
            'minimum_order_quantity' => '0', 'lead_time_days' => 0,
        ]);
        $this->demand($item, 'material_issue');
        $this->assertSame('1050.000', $this->daysOfSupply($item));
    }

    public function test_abc_marks_highest_usage_item_as_highest_priority(): void
    {
        Item::query()->update(['is_active' => false]);
        $item = Item::factory()->create(['is_active' => true]);
        $this->demand($item, 'material_issue');
        app(SettingsService::class)->set('inventory.abc.history_months', 12);
        app(SettingsService::class)->set('inventory.abc.a_ratio', 0.8);
        app(SettingsService::class)->set('inventory.abc.b_ratio', 0.95);
        $this->assertSame(['A' => 1, 'B' => 0, 'C' => 0], app(AbcClassificationService::class)->compute());
        $this->assertSame('A', $item->fresh()->abc_class);
    }
}
