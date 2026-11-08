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

/** Content-free refresh signal for the HR skill catalog and dependent views. */
final class SkillChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
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
            new PrivateChannel('entity.skill.visible'),
            new PrivateChannel('entity.employee_skill.visible'),
            new PrivateChannel("skill.{$this->hashId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'hr.skill.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        // Entity and actor hashes remain in the durable outbox for traceability
        // but are not sent to permission-wide competency viewers.
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => 'skill',
            'action' => $this->action,
            'version' => $this->version,
            'timestamp' => $this->timestamp,
        ];
    }
}
