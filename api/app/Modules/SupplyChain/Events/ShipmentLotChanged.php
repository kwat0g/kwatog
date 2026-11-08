<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Content-free shipment-lot signal; clients refetch authorized traceability APIs. */
final class ShipmentLotChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public function __construct(
        public string $shipmentLotHashId,
        public string $action,
        public string $version,
        public ?int $actorId = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("shipment_lot.{$this->shipmentLotHashId}"),
            new PrivateChannel('entity.shipment_lot.visible'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'shipment.lot.refresh';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'version' => $this->version,
            'action' => $this->action,
        ];
    }
}
