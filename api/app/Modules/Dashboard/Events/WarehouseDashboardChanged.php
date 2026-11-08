<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Refresh-only signal for the cached, API-authorized Warehouse dashboard. */
final class WarehouseDashboardChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $eventId,
        public readonly string $timestamp,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('dashboard.warehouse')];
    }

    public function broadcastAs(): string
    {
        return 'dashboard.warehouse.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => 'warehouse_dashboard',
            'timestamp' => $this->timestamp,
        ];
    }
}
