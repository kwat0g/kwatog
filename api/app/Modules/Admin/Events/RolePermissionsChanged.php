<?php

declare(strict_types=1);

namespace App\Modules\Admin\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Refresh signal for current members when their shared role access changes. */
final class RolePermissionsChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public function __construct(public string $roleHashId, ?string $eventId = null)
    {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("role.{$this->roleHashId}")];
    }

    public function broadcastAs(): string
    {
        return 'role.permissions.changed';
    }

    /** @return array{refresh: true, event_id: string} */
    public function broadcastWith(): array
    {
        return ['refresh' => true, 'event_id' => $this->eventId];
    }
}
