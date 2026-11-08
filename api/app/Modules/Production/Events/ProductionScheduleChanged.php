<?php

declare(strict_types=1);

namespace App\Modules\Production\Events;

use App\Common\Events\BroadcastEventEnvelope;
use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Content-free scheduler refresh; subscribers refetch their authorized snapshot. */
final class ProductionScheduleChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public string $timestamp;

    public function __construct(
        public string $scheduleHashId,
        public string $workOrderHashId,
        public string $action,
        public string $version,
        public ?string $actorHashId = null,
        ?string $eventId = null,
        ?string $timestamp = null,
        public array $recipientUserHashIds = [],
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
        $this->timestamp = $timestamp ?? now()->toISOString();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel("production_schedule.{$this->scheduleHashId}"),
            new PrivateChannel("chain.work_order.{$this->workOrderHashId}"),
            new PrivateChannel('entity.production_schedule.visible'),
            new PrivateChannel('production.dashboard'),
        ];

        foreach (array_unique($this->recipientUserHashIds) as $userHashId) {
            if (is_string($userHashId) && $userHashId !== '') {
                $channels[] = new PrivateChannel("production.operator.{$userHashId}");
            }
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'chain.refresh';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return BroadcastEventEnvelope::refresh(
            $this->eventId,
            'production_schedule',
            $this->scheduleHashId,
            $this->action,
            $this->version,
            $this->actorHashId,
            $this->timestamp,
        ) + ['hash_id' => $this->scheduleHashId];
    }
}
