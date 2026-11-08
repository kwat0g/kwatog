<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Content-free signal for authorized shift and assignment-list readers. */
final class ShiftChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public string $timestamp;

    public function __construct(
        public string $shiftHashId,
        public string $action,
        public string $version,
        public ?string $actorUserHashId = null,
        ?string $eventId = null,
        ?string $timestamp = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
        $this->timestamp = $timestamp ?? now()->toISOString();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("shift.{$this->shiftHashId}"),
            new PrivateChannel('entity.shift.visible'),
            new PrivateChannel('entity.shift.employee'),
            new PrivateChannel('entity.employee_shift_assignment.visible'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'attendance.shift.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => 'shift',
            'action' => $this->action,
            'version' => $this->version,
            'timestamp' => $this->timestamp,
        ];
    }
}
