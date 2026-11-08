<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Content-free fleet refresh signal; clients refetch their authorized API data. */
final class VehicleChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;
    public string $timestamp;

    public function __construct(
        public string $vehicleHashId,
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
            new PrivateChannel("vehicle.{$this->vehicleHashId}"),
            new PrivateChannel('entity.vehicle.visible'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'supply-chain.vehicle.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'timestamp' => $this->timestamp,
            'entity_type' => 'vehicle',
            'entity_id' => $this->vehicleHashId,
            'action' => $this->action,
            'version' => $this->version,
            'actor_id' => $this->actorHashId,
        ];
    }
}
