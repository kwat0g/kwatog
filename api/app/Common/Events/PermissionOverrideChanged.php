<?php

declare(strict_types=1);

namespace App\Common\Events;

use App\Common\Enums\PermissionOverrideType;
use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use App\Common\Events\Concerns\HasBroadcastEventId;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PermissionOverrideChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;
    use HasBroadcastEventId;

    public function __construct(
        public int $userId,
        public string $permissionSlug,
        public ?PermissionOverrideType $oldType,
        public ?PermissionOverrideType $newType,
        public string $reason,
        ?string $eventId = null,
    ) {
        $this->initializeBroadcastEventId($eventId);
    }

    public function broadcastOn(): array
    {
        $hashId = app('hashids')->encode($this->userId);

        return [
            // Keep the notification channel for older SPA bundles. Current
            // clients use the exact-user access channel so access refresh does
            // not depend on notifications.view being granted.
            new PrivateChannel("user.{$hashId}"),
            new PrivateChannel("user.access.{$hashId}"),
            new PrivateChannel("admin.user.{$hashId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'permission.override.changed';
    }

    public function broadcastWith(): array
    {
        return ['refresh' => true, 'event_id' => $this->eventId];
    }
}
