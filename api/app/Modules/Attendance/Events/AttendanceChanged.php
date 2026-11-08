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

/** Content-free refresh signal for authorized attendance and crew viewers. */
final class AttendanceChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public string $timestamp;

    /** @param list<string> $workOrderHashIds */
    public function __construct(
        public string $attendanceHashId,
        public string $action,
        public string $version,
        public array $workOrderHashIds = [],
        public ?string $employeeUserHashId = null,
        public ?string $actorId = null,
        ?string $eventId = null,
        ?string $timestamp = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
        $this->timestamp = $timestamp ?? now()->toISOString();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('entity.attendance.visible'),
            new PrivateChannel("attendance.{$this->attendanceHashId}"),
        ];

        // Production list viewers may not have Attendance permission, so they
        // cannot rely on the attendance entity channel to refresh crew and
        // readiness summaries. Route a content-free ping to their separately
        // authorized work-order list channel when this row affects an active WO.
        if ($this->workOrderHashIds !== []) {
            $channels[] = new PrivateChannel('entity.work_order.visible');
        }

        foreach ($this->workOrderHashIds as $workOrderHashId) {
            $channels[] = new PrivateChannel("chain.work_order.{$workOrderHashId}");
        }

        if ($this->employeeUserHashId !== null) {
            $channels[] = new PrivateChannel("employee.user.{$this->employeeUserHashId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'attendance.changed';
    }

    /**
     * List viewers may have row-level attendance scopes, so the row ID and
     * related work-order IDs stay in the private channel routing, not payload.
     *
     * @return array{refresh: true, event_id: string, entity_type: string, action: string, version: string, actor_id: ?string, timestamp: string}
     */
    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => 'attendance',
            'action' => $this->action,
            'version' => $this->version,
            'actor_id' => $this->actorId,
            'timestamp' => $this->timestamp,
        ];
    }
}
