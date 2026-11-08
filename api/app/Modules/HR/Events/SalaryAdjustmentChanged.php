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

/** Content-free refresh signal for authorized salary-adjustment readers. */
final class SalaryAdjustmentChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public string $timestamp;

    /** @param list<array{entity_type: string, hash_id: string}> $related */
    public function __construct(
        public string $hashId,
        public string $action,
        public string $version,
        public array $related = [],
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
            new PrivateChannel('entity.salary_adjustment.visible'),
            new PrivateChannel("hr.salary-adjustment.{$this->hashId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'hr.salary-adjustment.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => 'salary_adjustment',
            'hash_id' => $this->hashId,
            'action' => $this->action,
            'version' => $this->version,
            'actor_id' => $this->actorId,
            'timestamp' => $this->timestamp,
            'related' => $this->related,
        ];
    }
}
