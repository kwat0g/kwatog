<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\SupplyChain\Events\ShipmentChanged;
use App\Modules\SupplyChain\Models\Container;
use App\Modules\SupplyChain\Models\Shipment;
use App\Modules\SupplyChain\Exceptions\ShipmentStateConflictException;
use App\Modules\SupplyChain\Services\ContainerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ShipmentRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_and_child_writes_coalesce_to_one_content_free_refresh_per_transaction(): void
    {
        $actor = User::factory()->create();
        $purchaseOrder = PurchaseOrder::factory()->create();
        $shipment = Shipment::create([
            'shipment_number' => 'SHP-RT-'.substr(uniqid(), -6),
            'purchase_order_id' => $purchaseOrder->id,
            'status' => 'ordered',
            'created_by' => $actor->id,
        ]);

        DB::transaction(function () use ($shipment): void {
            $shipment->forceFill(['carrier' => 'Carrier A'])->save();
            Container::create([
                'shipment_id' => $shipment->id,
                'container_number' => 'CONT-RT-0001',
            ]);
        });

        $messages = DB::table('event_outbox')
            ->where('event_type', ShipmentChanged::class)
            ->orderBy('created_at')
            ->get();

        $this->assertCount(1, $messages);
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($shipment->hash_id, $payload['shipmentHashId']);
        $this->assertSame('updated', $payload['action']);
        $this->assertArrayHasKey('eventId', $payload);
        $this->assertArrayNotHasKey('carrier', $payload);
        $this->assertArrayNotHasKey('container_number', $payload);
    }

    public function test_container_update_rejects_a_stale_parent_shipment_version(): void
    {
        $actor = User::factory()->create();
        $purchaseOrder = PurchaseOrder::factory()->create();
        $shipment = Shipment::create([
            'shipment_number' => 'SHP-RT-'.substr(uniqid(), -6),
            'purchase_order_id' => $purchaseOrder->id,
            'status' => 'ordered',
            'created_by' => $actor->id,
        ]);
        $container = Container::create([
            'shipment_id' => $shipment->id,
            'container_number' => 'CONT-RT-0002',
        ]);
        $expectedShipmentVersion = $shipment->updated_at?->toISOString();
        $expectedContainerVersion = $container->updated_at?->toISOString();

        $this->travel(1)->seconds();
        $shipment->forceFill(['carrier' => 'Carrier B'])->save();

        $this->expectException(ShipmentStateConflictException::class);
        $this->expectExceptionMessage('Shipment changed since you opened this container.');
        app(ContainerService::class)->update(
            $container,
            ['seal_number' => 'STALE-SEAL'],
            $expectedContainerVersion,
            $expectedShipmentVersion,
        );
    }
}
