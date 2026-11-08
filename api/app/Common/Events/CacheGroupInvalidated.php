<?php

declare(strict_types=1);

namespace App\Common\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Content-free refresh signal after an allowlisted cache-group rotation was requested. */
final class CacheGroupInvalidated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $group,
        public string $eventId = '',
        public string $timestamp = '',
    ) {
        $this->eventId = $eventId !== '' ? $eventId : (string) Str::uuid();
        $this->timestamp = $timestamp !== '' ? $timestamp : now()->toISOString();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('cache-group.'.$this->group)];
    }

    public function broadcastAs(): string
    {
        return 'cache.group.invalidated';
    }

    /** @return array{refresh: true, group: string, event_id: string, timestamp: string} */
    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'group' => $this->group,
            'event_id' => $this->eventId,
            'timestamp' => $this->timestamp,
        ];
    }
}
