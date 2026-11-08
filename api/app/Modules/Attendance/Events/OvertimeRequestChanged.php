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

/** Content-free refresh signal for scoped overtime viewers. */
final class OvertimeRequestChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;
    public string $occurredAt;

    public function __construct(
        public string $overtimeRequestHashId,
        public ?string $ownerUserHashId,
        public string $action,
        public string $version,
        ?string $eventId = null,
        public ?string $actorUserHashId = null,
        ?string $occurredAt = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
        $this->occurredAt = $occurredAt ?? now()->toIso8601String();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel("overtime-request.{$this->overtimeRequestHashId}"),
            new PrivateChannel('entity.overtime_request.visible'),
        ];

        if ($this->ownerUserHashId !== null) {
            $channels[] = new PrivateChannel("overtime.user.{$this->ownerUserHashId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'attendance.overtime.changed';
    }

    /**
     * Channel names carry the target scope. Do not put employee IDs, request
     * details, reasons, hours, approver data, or actor metadata on the event.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => 'overtime_request',
            'action' => $this->action,
            'version' => $this->version,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
