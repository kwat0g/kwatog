<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\SupplyChain\Enums\ShipmentStatus;
use App\Modules\SupplyChain\Models\Shipment;
use App\Modules\SupplyChain\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ShipmentGrnHandoffTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Shipment, 1: PurchaseOrder} */
    private function shipmentWithReceivablePo(): array
    {
        $po = PurchaseOrder::factory()->create();
        $po->forceFill(['status' => PurchaseOrderStatus::Sent->value])->save();

        $item = Item::factory()->create(['is_active' => true]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Resin batch',
            'quantity' => '100.00',
            'unit' => 'kg',
            'unit_price' => '12.50',
            'total' => '1250.00',
            'quantity_received' => '0.00',
        ]);

        $by = User::factory()->create();
        $shipment = Shipment::create([
            'shipment_number' => 'SHP-HANDOFF-'.substr(uniqid(), -6),
            'purchase_order_id' => $po->id,
            'status' => ShipmentStatus::Cleared->value,
            'created_by' => $by->id,
        ]);

        return [$shipment, $po];
    }

    public function test_received_shipment_stages_one_linked_draft_grn(): void
    {
        [$shipment, $po] = $this->shipmentWithReceivablePo();

        app(ShipmentService::class)->updateStatus($shipment, ShipmentStatus::Received);

        $grn = GoodsReceiptNote::query()->where('shipment_id', $shipment->id)->firstOrFail();
        $this->assertSame(GrnStatus::Draft, $grn->status);
        $this->assertSame($po->id, $grn->purchase_order_id);
        $this->assertSame(1, GoodsReceiptNote::query()->where('purchase_order_id', $po->id)->count());
        $this->assertSame($grn->id, $shipment->fresh()->goodsReceiptNote->id);
    }

    public function test_received_shipment_reuses_the_expected_po_draft(): void
    {
        [$shipment, $po] = $this->shipmentWithReceivablePo();
        $expected = app(GrnService::class)->createDraftForPo($po, null);

        app(ShipmentService::class)->updateStatus($shipment, ShipmentStatus::Received);

        $this->assertSame($expected?->id, GoodsReceiptNote::query()->where('shipment_id', $shipment->id)->value('id'));
        $this->assertSame(1, GoodsReceiptNote::query()->where('purchase_order_id', $po->id)->count());
    }

    public function test_non_receivable_po_cannot_be_marked_as_received_without_a_grn_handoff(): void
    {
        [$shipment] = $this->shipmentWithReceivablePo();
        PurchaseOrder::query()
            ->whereKey($shipment->purchase_order_id)
            ->update(['status' => PurchaseOrderStatus::Received->value]);

        try {
            app(ShipmentService::class)->updateStatus($shipment, ShipmentStatus::Received);
            $this->fail('A received shipment must have a receiving handoff.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('not open for receiving', $e->getMessage());
        }

        $this->assertSame(ShipmentStatus::Cleared, $shipment->fresh()->status);
    }
}
