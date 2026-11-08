<?php

declare(strict_types=1);

namespace App\Modules\Production\Events;

use App\Common\Events\BroadcastEventEnvelope;
use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Content-free refresh signal for authorized production-routing readers. */
final class ProductionRoutingChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public string $timestamp;

    public function __construct(
        public string $routingHashId,
        public string $productHashId,
        public string $action,
        public string $version,
        public ?string $actorHashId = null,
        ?string $eventId = null,
        ?string $timestamp = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
        $this->timestamp = $timestamp ?? now()->toISOString();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('entity.production_routing.visible'),
            new PrivateChannel("production.routing.{$this->routingHashId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'production.routing.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return BroadcastEventEnvelope::refresh(
            $this->eventId,
            'product_routing',
            $this->routingHashId,
            $this->action,
            $this->version,
            $this->actorHashId,
            $this->timestamp,
            [[
                'entity_type' => 'product',
                'hash_id' => $this->productHashId,
            ]],
        ) + ['hash_id' => $this->routingHashId];
    }
}
