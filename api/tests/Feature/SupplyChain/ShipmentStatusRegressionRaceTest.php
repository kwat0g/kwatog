<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\SupplyChain\Enums\ShipmentStatus;
use App\Modules\SupplyChain\Enums\ShipmentDocumentType;
use App\Modules\SupplyChain\Models\Container;
use App\Modules\SupplyChain\Models\Shipment;
use App\Modules\SupplyChain\Models\ShipmentDocument;
use App\Modules\SupplyChain\Services\ShipmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P01-01 shape on P72 (shipment / ImpEx lifecycle): updateStatus() evaluated
 * `canTransitionTo` on the *passed* model with no transaction and no row lock.
 * A stale transition from an earlier snapshot can overwrite a shipment that a
 * concurrent update already advanced all the way to Received — the ImpEx chain
 * regresses. DeliveryService (same shape) was already locked; ShipmentService
 * missed it.
 */
class ShipmentStatusRegressionRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function shipment(): Shipment
    {
        $po = PurchaseOrder::factory()->create();
        $po->forceFill(['status' => PurchaseOrderStatus::Sent->value])->save();
        $item = Item::factory()->create(['is_active' => true]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Race-test resin',
            'quantity' => '1.00',
            'unit' => 'kg',
            'unit_price' => '1.00',
            'total' => '1.00',
            'quantity_received' => '0.00',
        ]);
        $by = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);

        $shipment = Shipment::create([
            'shipment_number'  => 'SHP-RACE-'.substr(uniqid(), -6),
            'purchase_order_id' => $po->id,
            'status'            => ShipmentStatus::Ordered->value,
            'created_by'        => $by->id,
        ]);

        foreach ([
            ShipmentDocumentType::BillOfLading,
            ShipmentDocumentType::CommercialInvoice,
            ShipmentDocumentType::PackingList,
            ShipmentDocumentType::ImportEntry,
            ShipmentDocumentType::BocRelease,
        ] as $type) {
            ShipmentDocument::create([
                'shipment_id' => $shipment->id,
                'document_type' => $type->value,
                'file_path' => "shipments/{$shipment->id}/{$type->value}.pdf",
                'original_filename' => $type->value.'.pdf',
                'uploaded_by' => $by->id,
                'uploaded_at' => now(),
            ]);
        }
        Container::create([
            'shipment_id' => $shipment->id,
            'container_number' => 'RACE'.substr(uniqid(), -7),
            'size' => '40ft',
            'type' => 'dry',
        ]);

        return $shipment;
    }

    public function test_stale_transition_cannot_regress_a_received_shipment(): void
    {
        $shipment = $this->shipment();
        $svc = app(ShipmentService::class);

        // The stale operator fetched the shipment while it was `ordered`.
        $stale = Shipment::find($shipment->id);

        // The live operator drives it through the full chain to Received.
        foreach ([
            ShipmentStatus::Shipped,
            ShipmentStatus::InTransit,
            ShipmentStatus::Customs,
            ShipmentStatus::Cleared,
            ShipmentStatus::Received,
        ] as $next) {
            $svc->updateStatus(Shipment::find($shipment->id), $next);
        }
        $this->assertSame(ShipmentStatus::Received, $shipment->refresh()->status);

        // Stale operator tries Shipped from the old snapshot — must be blocked.
        try {
            $svc->updateStatus($stale, ShipmentStatus::Shipped);
            $this->fail('A stale transition must not regress a received shipment.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Cannot transition', $e->getMessage());
        }

        $this->assertSame(ShipmentStatus::Received, $shipment->refresh()->status);
    }
}
