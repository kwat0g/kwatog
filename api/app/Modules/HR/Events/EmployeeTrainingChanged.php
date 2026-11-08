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

/** Content-free employee-training refresh signal for scoped readers. */
final class EmployeeTrainingChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public string $timestamp;

    public function __construct(
        public string $hashId,
        public string $action,
        public string $version,
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
        return [
            new PrivateChannel('entity.employee_training.visible'),
            new PrivateChannel("employee_training.{$this->hashId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'hr.employee-training.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        // Record and actor identifiers remain in the durable server-side
        // outbox. List subscribers receive only a refresh signal; a record
        // subscriber already knows the record from its authorized channel.
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => 'employee_training',
            'action' => $this->action,
            'version' => $this->version,
            'timestamp' => $this->timestamp,
        ];
    }
}
