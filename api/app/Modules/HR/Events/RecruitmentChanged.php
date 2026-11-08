<?php

declare(strict_types=1);

namespace App\Modules\HR\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Refresh-only signal for authorized HR recruitment views. */
final class RecruitmentChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public string $timestamp;

    /** @param list<array{entity_type: string, hash_id: string}> $related */
    public function __construct(
        public string $entityType,
        public string $entityId,
        public array $related,
        public string $action,
        public string $version,
        ?string $eventId = null,
        ?string $timestamp = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
        $this->timestamp = $timestamp ?? now()->toISOString();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel("{$this->entityType}.{$this->entityId}"),
            new PrivateChannel("entity.{$this->entityType}.visible"),
        ];

        foreach ($this->related as $related) {
            if (($related['entity_type'] ?? null) === 'job_posting'
                && is_string($related['hash_id'] ?? null)
                && $related['hash_id'] !== '') {
                $channels[] = new PrivateChannel("job_posting.{$related['hash_id']}");
            }
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'hr.recruitment.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'related' => $this->related,
            'action' => $this->action,
            'version' => $this->version,
            'timestamp' => $this->timestamp,
        ];
    }
}
