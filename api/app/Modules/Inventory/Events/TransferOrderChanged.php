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
use Illuminate\Support\Str;

/** Content-free list/detail refresh signal for Inventory transfer orders. */
final class TransferOrderChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;
    public string $timestamp;

    public function __construct(
        public string $hashId,
        public string $action,
        public string $version,
        ?string $eventId = null,
        public ?string $actorHashId = null,
        ?string $timestamp = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
        $this->timestamp = $timestamp ?? now()->toIso8601String();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("inventory.transfer-order.{$this->hashId}"),
            new PrivateChannel('entity.transfer_order.visible'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'inventory.transfer-order.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            ...BroadcastEventEnvelope::refresh(
                eventId: $this->eventId,
                entityType: 'transfer_order',
                entityId: $this->hashId,
                action: $this->action,
                version: $this->version,
                actorId: $this->actorHashId,
                timestamp: $this->timestamp,
            ),
            // Preserve the previous key for any legacy event consumer.
            'hash_id' => $this->hashId,
        ];
    }
}
