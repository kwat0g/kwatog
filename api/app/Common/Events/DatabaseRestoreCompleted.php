<?php

declare(strict_types=1);

namespace App\Common\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Content-free signal telling connected sessions to reload after DB replacement. */
final class DatabaseRestoreCompleted implements ShouldBroadcast, ShouldDispatchAfterCommit
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
        return [
            new PrivateChannel('system.refresh'),
            new PrivateChannel('portal.supplier.system'),
            new PrivateChannel('portal.customer.system'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'database.restore.completed';
    }

    /** @return array{refresh_required: true, event_id: string, timestamp: string} */
    public function broadcastWith(): array
    {
        return [
            'refresh_required' => true,
            'event_id' => $this->eventId,
            'timestamp' => $this->timestamp,
        ];
    }
}
