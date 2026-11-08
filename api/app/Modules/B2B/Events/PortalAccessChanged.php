<?php

declare(strict_types=1);

namespace App\Modules\B2B\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Content-free signal for authorized B2B portal access administrators. */
final class PortalAccessChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public string $timestamp;

    public function __construct(?string $eventId = null, ?string $timestamp = null)
    {
        $this->eventId = $eventId ?? (string) Str::uuid();
        $this->timestamp = $timestamp ?? now()->toISOString();
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('entity.b2b_portal_access.visible');
    }

    public function broadcastAs(): string
    {
        return 'b2b.portal-access.changed';
    }

    /** @return array{refresh: true, event_id: string, entity_type: string, timestamp: string} */
    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => 'b2b_portal_access',
            'timestamp' => $this->timestamp,
        ];
    }
}
