<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemUomConversion;
use App\Modules\Inventory\Models\Uom;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Models\Inspection;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrnRejectedReturnUomTest extends TestCase
{
    use RefreshDatabase;

    private function receipt(string $purchaseUom, string $quantity): array
    {
        $receiver = User::factory()->create(['is_active' => true]);
        $item = Item::factory()->create(['unit_of_measure' => 'KG', 'is_active' => true]);
        $kg = Uom::create(['code' => 'KG', 'name' => 'Kilogram']);
        $bag = Uom::create(['code' => 'BAG', 'name' => 'Bag']);
        ItemUomConversion::create([
            'item_id' => $item->id,
            'from_uom_id' => $bag->id,
            'to_uom_id' => $kg->id,
            'factor' => '5.000000',
        ]);
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'created_by' => $receiver->id,
        ]);
        $line = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Resin',
            'quantity' => $purchaseUom === 'BAG' ? '2' : '10',
            'unit' => $purchaseUom,
            'unit_price' => '25.00',
            'total' => $purchaseUom === 'BAG' ? '50.00' : '250.00',
        ]);
        $service = app(GrnService::class);
        $grn = $service->create($po, [[
            'purchase_order_item_id' => $line->id,
            'item_id' => $item->id,
            'location_id' => WarehouseLocation::factory()->create()->hash_id,
            'quantity_received' => $quantity,
        ]], [], $receiver);

        return [$service, $grn, $receiver];
    }

    private function expectBasePricedReturn(string $quantity): void
    {
        $this->mock(ReturnRequestService::class)
            ->shouldReceive('openSupplierReturnForReversedGoods')
            ->once()
            ->withArgs(function ($vendorId, $purchaseOrderId, $goodsReceiptNoteId, $lines) use ($quantity): bool {
                $this->assertCount(1, $lines);
                $this->assertSame($quantity, $lines[0]['quantity']);
                $this->assertSame('5.0000', $lines[0]['unit_price']); // ₱25/BAG ÷ 5 KG/BAG

                return true;
            })
            ->andReturn(new ReturnRequest);
    }

    public function test_full_rejection_passes_base_quantity_and_base_price_to_supplier_return(): void
    {
        [$service, $grn, $receiver] = $this->receipt('BAG', '10');
        $this->expectBasePricedReturn('10.000');

        $service->reject($grn, 'Incoming QC rejected both bags', $receiver);
    }

    public function test_remainder_rejection_passes_base_quantity_and_base_price_to_supplier_return(): void
    {
        [$service, $grn, $receiver] = $this->receipt('BAG', '10');
        $checker = User::factory()->create(['is_active' => true]);
        Inspection::query()->where('entity_type', 'grn')->where('entity_id', $grn->id)
            ->update(['status' => 'passed', 'reviewed_by' => $checker->id, 'reviewed_at' => now()]);
        $service->partialAccept($grn, [$grn->items->first()->id => '5.000'], $receiver);
        $this->expectBasePricedReturn('5.000');

        $service->rejectRemainder($grn->fresh(), 'Remaining bag rejected', $receiver);
    }

    public function test_same_uom_rejection_keeps_the_po_price(): void
    {
        [$service, $grn, $receiver] = $this->receipt('KG', '5');

        $service->reject($grn, 'Five kilograms rejected', $receiver);

        $line = ReturnRequest::query()->where('source_key', 'grn-rejection:'.$grn->id)
            ->firstOrFail()->items()->firstOrFail();
        $this->assertSame('5.000', (string) $line->quantity);
        $this->assertSame('25.00', (string) $line->unit_price);
        $this->assertSame('125.00', (string) $line->total);
    }
}
