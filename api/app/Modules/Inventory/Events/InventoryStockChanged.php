<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Common\Events\BroadcastEventEnvelope;
use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Content-free stock ping; each private audience refetches through its authorized API scope. */
class InventoryStockChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $eventId,
        public readonly string $version,
        public readonly \DateTimeInterface $occurredAt,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('inventory.dashboard'),
            new PrivateChannel('dashboard.warehouse'),
            new PrivateChannel('entity.warehouse_map.visible'),
            new PrivateChannel('entity.item.visible'),
            new PrivateChannel('entity.work_order.visible'),
            new PrivateChannel('entity.maintenance_work_order.visible'),
            new PrivateChannel('entity.ncr.visible'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'InventoryStockChanged';
    }

    public function broadcastWith(): array
    {
        $timestamp = $this->occurredAt->format(DATE_ATOM);

        return [
            ...BroadcastEventEnvelope::refresh(
                eventId: $this->eventId,
                entityType: 'inventory_stock',
                entityId: null,
                action: 'updated',
                version: $this->version,
                actorId: null,
                timestamp: $timestamp,
            ),
            // Existing consumers use occurred_at for activity displays.
            'occurred_at' => $timestamp,
        ];
    }
}
