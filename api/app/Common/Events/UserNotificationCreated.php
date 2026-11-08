<?php

declare(strict_types=1);

namespace App\Common\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use App\Common\Events\Concerns\HasBroadcastEventId;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserNotificationCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;
    use HasBroadcastEventId;

    public function __construct(
        public readonly int $userId,
        public readonly string $notificationId,
        public readonly string $type,
        ?string $eventId = null,
    ) {
        $this->initializeBroadcastEventId($eventId);
    }

    public function broadcastOn(): array
    {
        $hashId = app('hashids')->encode($this->userId);

        return [new PrivateChannel("user.{$hashId}")];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->notificationId, 'type' => $this->type, 'event_id' => $this->eventId];
    }
}
