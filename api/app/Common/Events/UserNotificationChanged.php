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

/** Content-free signal for changes to one user's internal notification inbox. */
final class UserNotificationChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $eventId;

    public function __construct(
        public readonly int $userId,
        public readonly string $action,
        public readonly ?string $notificationId = null,
        public readonly ?string $occurredAt = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }

    public function broadcastOn(): array
    {
        $hashId = app('hashids')->encode($this->userId);

        return [new PrivateChannel("user.{$hashId}")];
    }

    public function broadcastAs(): string
    {
        return 'notification.changed';
    }

    public function broadcastWith(): array
    {
        return array_filter([
            'event_id' => $this->eventId,
            'action' => $this->action,
            'id' => $this->notificationId,
            'occurred_at' => $this->occurredAt,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
