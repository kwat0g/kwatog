<?php

declare(strict_types=1);

namespace App\Modules\Maintenance\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Content-free refresh signal for preventive maintenance schedule viewers. */
final class MaintenanceScheduleChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public function __construct(
        public string $hashId,
        public string $action,
        public string $version,
        public ?string $actorId,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("maintenance_schedule.{$this->hashId}"),
            new PrivateChannel('entity.maintenance_schedule.visible'),
            new PrivateChannel('maintenance.dashboard'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'maintenance.schedule.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        // The dashboard channel has a wider scope than an individual schedule.
        // Send only a ping; each recipient refetches through its own API access.
        return ['refresh' => true, 'event_id' => $this->eventId];
    }
}
