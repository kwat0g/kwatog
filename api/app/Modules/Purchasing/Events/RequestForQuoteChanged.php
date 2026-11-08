<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Events;

use App\Common\Events\BroadcastEventEnvelope;
use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Internal RFQ refresh signal. Each recipient refetches through the RFQ API,
 * whose per-user resource controls sealed supplier and permission-scoped data.
 */
class RequestForQuoteChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;
    public string $timestamp;

    public function __construct(
        public string $rfqHashId,
        public string $action,
        public string $version,
        public ?int $actorId = null,
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
            new PrivateChannel("rfq.{$this->rfqHashId}"),
            new PrivateChannel('entity.request_for_quote.visible'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'rfq.refresh';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return BroadcastEventEnvelope::refresh(
            eventId: $this->eventId,
            entityType: 'request_for_quote',
            entityId: $this->rfqHashId,
            action: $this->action,
            version: $this->version,
            actorId: $this->actorHashId,
            timestamp: $this->timestamp,
        );
    }
}
