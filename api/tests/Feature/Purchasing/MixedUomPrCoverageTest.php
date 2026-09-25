<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemUomConversion;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestConversionStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use App\Modules\Purchasing\Services\OpenSupplyService;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MixedUomPrCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function resin(): Item
    {
        $kg = Uom::create(['code' => 'KG', 'name' => 'Kilogram']);
        $bag = Uom::create(['code' => 'BAG', 'name' => 'Bag']);
        $item = Item::factory()->create(['unit_of_measure' => 'KG']);
        ItemUomConversion::create([
            'item_id' => $item->id,
            'from_uom_id' => $bag->id,
            'to_uom_id' => $kg->id,
            'factor' => '5.000000',
        ]);

        return $item;
    }

    private function request(Item $item, User $user, string $quantity, string $unit): array
    {
        $pr = PurchaseRequest::factory()->create(['requested_by' => $user->id, 'department_id' => null]);
        $pr->forceFill(['status' => PurchaseRequestStatus::Approved])->save();
        $line = PurchaseRequestItem::create([
            'purchase_request_id' => $pr->id,
            'item_id' => $item->id,
            'description' => 'Resin',
            'quantity' => $quantity,
            'unit' => $unit,
            'estimated_unit_price' => '10.00',
        ]);

        return [$pr, $line];
    }

    private function order(PurchaseOrderService $service, PurchaseRequest $pr, PurchaseRequestItem $line, Item $item, Vendor $vendor, User $user, string $quantity, string $unit, bool $completeConversion = false): void
    {
        $service->create([
            'vendor_id' => $vendor->id,
            'purchase_request_id' => $pr->id,
            'items' => [[
                'item_id' => $item->id,
                'purchase_request_item_id' => $line->id,
                'description' => 'Resin',
                'quantity' => $quantity,
                'unit' => $unit,
                'unit_price' => '10.00',
            ]],
        ], $user, completeConversion: $completeConversion);
    }

    public function test_open_request_nets_linked_po_quantities_in_base_units_once(): void
    {
        $user = User::factory()->create();
        $item = $this->resin();
        $vendor = Vendor::factory()->create();
        [$pr, $line] = $this->request($item, $user, '10', 'KG');
        $service = app(PurchaseOrderService::class);

        $this->order($service, $pr, $line, $item, $vendor, $user, '1', 'BAG');
        $this->assertSame('5.000', app(OpenSupplyService::class)->openRequestBaseQuantity($item->id));

        // Both units on the same source line must be summed, with no second
        // deduction for the same PO through the PR's parent link.
        $this->order($service, $pr, $line, $item, $vendor, $user, '2', 'KG');
        $this->assertSame('3.000', app(OpenSupplyService::class)->openRequestBaseQuantity($item->id));
    }

    public function test_po_guard_rejects_overordering_even_when_bags_look_smaller_than_kilos(): void
    {
        $user = User::factory()->create();
        $item = $this->resin();
        $vendor = Vendor::factory()->create();
        [$pr, $line] = $this->request($item, $user, '10', 'KG');
        $service = app(PurchaseOrderService::class);
        $this->order($service, $pr, $line, $item, $vendor, $user, '1', 'BAG');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('would exceed the requested quantity');
        $this->order($service, $pr, $line, $item, $vendor, $user, '1.001', 'BAG');
    }

    public function test_alt_unit_pr_remains_partial_and_converter_orders_only_remaining_bags(): void
    {
        $user = User::factory()->create();
        $item = $this->resin();
        $vendor = Vendor::factory()->create();
        [$pr, $line] = $this->request($item, $user, '2', 'BAG');
        $service = app(PurchaseOrderService::class);

        $this->order($service, $pr, $line, $item, $vendor, $user, '5', 'KG', true);
        $this->assertSame(PurchaseRequestConversionStatus::Partial, $pr->fresh()->po_conversion_status);
        $this->assertSame('5.000', app(OpenSupplyService::class)->openRequestBaseQuantity($item->id));

        $created = $service->convertFromPr($pr->fresh(), [$line->id => $vendor->id], $user);
        $this->assertCount(1, $created);
        $this->assertSame('1.00', $created[0]->items()->firstOrFail()->quantity);
        $this->assertSame('BAG', $created[0]->items()->firstOrFail()->unit);
        $this->assertSame(PurchaseRequestStatus::Converted, $pr->fresh()->status);
    }

    public function test_in_transit_subtracts_base_received_from_base_ordered_quantity(): void
    {
        $item = $this->resin();
        $po = PurchaseOrder::factory()->create(['status' => PurchaseOrderStatus::PartiallyReceived->value]);
        $line = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Resin',
            'quantity' => '2',
            'unit' => 'BAG',
            'unit_price' => '10.00',
            'total' => '20.00',
            // GRN persists this running total in the item's base UOM (KG).
            'quantity_received' => '5',
        ]);

        $supply = app(OpenSupplyService::class);
        $this->assertSame('5.000000', $supply->inTransitBaseQuantity($item->id));

        $line->update(['quantity_received' => '10']);
        $this->assertSame('0.000000', $supply->inTransitBaseQuantity($item->id));

        $line->update(['quantity_received' => '1.250']);
        $this->assertSame('8.750000', $supply->inTransitBaseQuantity($item->id));
    }

    public function test_converter_rejects_a_remaining_base_quantity_that_cannot_fit_purchase_unit_precision(): void
    {
        $user = User::factory()->create();
        $item = $this->resin();
        $item->uomConversions()->update(['factor' => '7.000000']);
        $vendor = Vendor::factory()->create();
        [$pr, $line] = $this->request($item, $user, '1', 'BAG');
        $service = app(PurchaseOrderService::class);
        $this->order($service, $pr, $line, $item, $vendor, $user, '1', 'KG', true);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('cannot be represented');
        $service->convertFromPr($pr->fresh(), [$line->id => $vendor->id], $user);
    }
}
