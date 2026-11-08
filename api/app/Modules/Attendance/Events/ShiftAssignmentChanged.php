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

/** Refresh-only signal for an employee's shift and authorized assignment lists. */
final class ShiftAssignmentChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public string $timestamp;

    public function __construct(
        public string $assignmentHashId,
        public string $employeeHashId,
        public string $action,
        public string $version,
        public ?string $actorUserHashId = null,
        /** @var list<string> */
        public array $relatedEmployeeHashIds = [],
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
            new PrivateChannel("shift-assignment.{$this->assignmentHashId}"),
            new PrivateChannel('entity.employee_shift_assignment.visible'),
            new PrivateChannel("attendance.employee-shift.{$this->employeeHashId}"),
            new PrivateChannel("employee.onboarding.{$this->employeeHashId}"),
        ];

        foreach (array_unique($this->relatedEmployeeHashIds) as $employeeHashId) {
            if (! is_string($employeeHashId) || $employeeHashId === '' || $employeeHashId === $this->employeeHashId) {
                continue;
            }
            $channels[] = new PrivateChannel("attendance.employee-shift.{$employeeHashId}");
            $channels[] = new PrivateChannel("employee.onboarding.{$employeeHashId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'attendance.shift-assignment.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => 'employee_shift_assignment',
            'action' => $this->action,
            'version' => $this->version,
            'timestamp' => $this->timestamp,
        ];
    }
}
