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
use Illuminate\Support\Str;

/** Content-free refresh signal for users authorized to read the PPC dashboard. */
final class PpcDashboardChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $eventId = '',
        public string $timestamp = '',
    ) {
        $this->eventId = $eventId !== '' ? $eventId : (string) Str::uuid();
        $this->timestamp = $timestamp !== '' ? $timestamp : now()->toISOString();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('dashboard.ppc')];
    }

    public function broadcastAs(): string
    {
        return 'ppc.dashboard.changed';
    }

    /** @return array{refresh: true, event_id: string, entity_type: string, timestamp: string} */
    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => 'ppc_dashboard',
            'timestamp' => $this->timestamp,
        ];
    }
}
