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

/** Content-free refresh signal for the owner's dashboard layout in sibling tabs. */
final class DashboardLayoutChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $userHashId,
        public readonly string $action,
        public readonly string $version,
        public readonly string $eventId,
        public readonly string $timestamp,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("user.preferences.{$this->userHashId}");
    }

    public function broadcastAs(): string
    {
        return 'dashboard.layout.changed';
    }

    /** @return array{refresh: true, event_id: string, entity_type: string, action: string, version: string, timestamp: string} */
    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => 'dashboard_layout',
            'action' => $this->action,
            'version' => $this->version,
            'timestamp' => $this->timestamp,
        ];
    }
}
