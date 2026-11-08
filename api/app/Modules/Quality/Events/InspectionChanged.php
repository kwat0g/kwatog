<?php

declare(strict_types=1);

namespace App\Modules\Quality\Events;

use App\Common\Events\BroadcastEventEnvelope;
use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Content-free inspection refresh signal; recipients refetch their authorized API view. */
class InspectionChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public string $timestamp;

    public function __construct(
        public string $inspectionHashId,
        public string $action,
        public string $version,
        // Retained only so pending pre-deploy outbox rows remain decodable.
        public ?int $actorId = null,
        ?string $eventId = null,
        public ?string $actorHashId = null,
        ?string $timestamp = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
        $this->timestamp = $timestamp ?? now()->toISOString();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("inspection.{$this->inspectionHashId}"),
            new PrivateChannel('entity.inspection.visible'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'inspection.refresh';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return BroadcastEventEnvelope::refresh(
            eventId: $this->eventId,
            entityType: 'inspection',
            entityId: $this->inspectionHashId,
            action: $this->action,
            version: $this->version,
            actorId: $this->actorHashId,
            timestamp: $this->timestamp,
        );
    }
}
