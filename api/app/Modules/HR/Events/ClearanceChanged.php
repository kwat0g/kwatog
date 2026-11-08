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

/** Content-free refresh signal for clearance viewers authorized by the API scope. */
final class ClearanceChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public string $timestamp;

    /** @param list<string> $viewerUserHashIds */
    public function __construct(
        public string $hashId,
        public string $action,
        public string $version,
        public ?string $actorId,
        public array $viewerUserHashIds = [],
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
            new PrivateChannel("clearance.{$this->hashId}"),
            ...array_map(
                static fn (string $userHashId): PrivateChannel => new PrivateChannel("hr.clearance.user.{$userHashId}"),
                $this->viewerUserHashIds,
            ),
        ];
    }

    public function broadcastAs(): string
    {
        return 'hr.clearance.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => 'clearance',
            'hash_id' => $this->hashId,
            'action' => $this->action,
            'version' => $this->version,
            'actor_id' => $this->actorId,
            'timestamp' => $this->timestamp,
        ];
    }
}
