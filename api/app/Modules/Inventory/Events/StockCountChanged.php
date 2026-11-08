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

/** Content-free refresh signal for a stock-count session aggregate. */
final class StockCountChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;
    public string $timestamp;

    public function __construct(
        public string $sessionHashId,
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
            new PrivateChannel("inventory.stock-count-session.{$this->sessionHashId}"),
            new PrivateChannel('entity.stock_count_session.visible'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'inventory.stock-count.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        // Count quantities, variance, lot and notes stay on the authenticated
        // API representation, where each viewer is authorized. The actor is
        // represented only by the scoped, pseudonymous user hash.
        return BroadcastEventEnvelope::refresh(
            eventId: $this->eventId,
            entityType: 'stock_count_session',
            entityId: $this->sessionHashId,
            action: $this->action,
            version: $this->version,
            actorId: $this->actorHashId,
            timestamp: $this->timestamp,
        );
    }
}
