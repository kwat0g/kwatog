<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Events;

use App\Common\Events\BroadcastEventEnvelope;
use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Content-free refresh signal for maintenance work-order viewers. */
final class MaintenanceWorkOrderChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public string $timestamp;

    /** @param list<string> $viewerUserHashIds */
    public function __construct(
        public string $hashId,
        public string $action,
        public string $version,
        public ?string $actorId,
        public array $viewerUserHashIds = [],
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
            new PrivateChannel("maintenance_work_order.{$this->hashId}"),
            new PrivateChannel('entity.maintenance_work_order.visible'),
            new PrivateChannel('maintenance.dashboard'),
            ...array_map(
                static fn (string $userHashId): PrivateChannel => new PrivateChannel("maintenance.user.{$userHashId}"),
                $this->viewerUserHashIds,
            ),
        ];
    }

    public function broadcastAs(): string
    {
        return 'maintenance.work-order.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return BroadcastEventEnvelope::refresh(
            $this->eventId,
            'maintenance_work_order',
            $this->hashId,
            $this->action,
            $this->version,
            $this->actorId,
            $this->timestamp,
        ) + ['hash_id' => $this->hashId];
    }
}
