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
use InvalidArgumentException;

/** Batched, content-free refresh for inboxes affected by retention pruning. */
final class NotificationsPruned implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    private const MAX_RECIPIENT_CHANNELS = 50;

    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public string $timestamp;

    /**
     * @param 'internal'|'customer'|'supplier' $realm
     * @param list<int> $userIds
     */
    public function __construct(
        public string $realm,
        public array $userIds,
        ?string $eventId = null,
        ?string $timestamp = null,
    ) {
        if (! in_array($realm, ['internal', 'customer', 'supplier'], true)) {
            throw new InvalidArgumentException('Unsupported notification-prune audience.');
        }

        $this->userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($this->userIds === [] || count($this->userIds) > self::MAX_RECIPIENT_CHANNELS) {
            throw new InvalidArgumentException('Notification-prune batches must target between 1 and 50 users.');
        }

        $this->eventId = $eventId ?? (string) Str::uuid();
        $this->timestamp = $timestamp ?? now()->toISOString();
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(function (int $userId): PrivateChannel {
            $userHashId = app('hashids')->encode($userId);
            $channel = $this->realm === 'internal'
                ? "user.{$userHashId}"
                : "portal.{$this->realm}.user.{$userHashId}";

            return new PrivateChannel($channel);
        }, $this->userIds);
    }

    public function broadcastAs(): string
    {
        return $this->realm === 'internal'
            ? 'notification.changed'
            : 'portal.notification.changed';
    }

    /** @return array{event_id: string, action: string, occurred_at: string} */
    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'action' => 'pruned',
            'occurred_at' => $this->timestamp,
        ];
    }
}
