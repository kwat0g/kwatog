<?php

declare(strict_types=1);

namespace App\Modules\HR\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Content-free refresh for an employee's skill collection and scoped matrices. */
final class EmployeeSkillChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public string $timestamp;

    public function __construct(
        public string $employeeHashId,
        public string $action,
        public string $version,
        public ?string $actorHashId = null,
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
            new PrivateChannel('entity.employee_skill.visible'),
            new PrivateChannel("employee.skills.{$this->employeeHashId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'hr.employee-skill.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        // List viewers can only learn that their authorized result may have
        // changed. The employee hash is retained in the durable outbox and the
        // private record channel, not sent to the permission-wide list channel.
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => 'employee_skill',
            'action' => $this->action,
            'version' => $this->version,
            'actor_id' => $this->actorHashId,
            'timestamp' => $this->timestamp,
        ];
    }
}
