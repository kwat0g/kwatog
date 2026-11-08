<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Events;

use App\Common\Events\BroadcastEventEnvelope;
use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use App\Common\Events\Concerns\HasBroadcastEventId;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Refresh-only ping for users who can see at least one configurable widget. */
final class DashboardWidgetDataChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;
    use HasBroadcastEventId;

    public string $timestamp;

    public function __construct(?string $eventId = null, ?string $timestamp = null)
    {
        $this->initializeBroadcastEventId($eventId);
        $this->timestamp = $timestamp ?? now()->toIso8601String();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('dashboard.widgets')];
    }

    public function broadcastAs(): string
    {
        return 'dashboard.widgets.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return BroadcastEventEnvelope::refresh(
            eventId: $this->eventId,
            entityType: 'dashboard_widget_data',
            entityId: null,
            action: 'updated',
            version: $this->timestamp,
            actorId: null,
            timestamp: $this->timestamp,
        );
    }
}
