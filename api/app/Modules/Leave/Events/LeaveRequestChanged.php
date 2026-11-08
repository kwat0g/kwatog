<?php

declare(strict_types=1);

namespace App\Modules\Leave\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Private, content-free refresh signal for scoped leave viewers. */
final class LeaveRequestChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public function __construct(
        public string $leaveRequestHashId,
        public string $action,
        public string $version,
        public ?string $actorHashId = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("leave-request.{$this->leaveRequestHashId}"),
            new PrivateChannel('entity.leave_request.visible'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'leave.request.changed';
    }

    /**
     * Leave rows have employee and department scopes. Do not expose record,
     * actor, or status metadata on the broader list channel; every client
     * refetches the rows its own API identity is allowed to see.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => 'leave_request',
        ];
    }
}
