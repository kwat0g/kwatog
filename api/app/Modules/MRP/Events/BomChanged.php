<?php

declare(strict_types=1);

namespace App\Modules\MRP\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Content-free BOM refresh signal; each recipient refetches its authorized API. */
final class BomChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public string $timestamp;

    public function __construct(
        public string $bomHashId,
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
            new PrivateChannel("production.bom.{$this->bomHashId}"),
            new PrivateChannel('entity.bom.visible'),
            new PrivateChannel("mrp.product-bom.{$this->productHashId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chain.refresh';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => 'bom',
            'hash_id' => $this->bomHashId,
            'action' => $this->action,
            'version' => $this->version,
            'actor_id' => $this->actorHashId,
            'related' => [['entity_type' => 'product', 'hash_id' => $this->productHashId]],
            'timestamp' => $this->timestamp,
        ];
    }
}
