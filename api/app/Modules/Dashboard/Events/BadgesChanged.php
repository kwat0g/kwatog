<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use App\Common\Events\Concerns\HasBroadcastEventId;
use App\Modules\Auth\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Polish Task S2 (real-time) — fired whenever badge-affecting data changes.
 * Carries no business data; clients refetch their own permission-scoped
 * badge and approval-board responses through the APIs.
 */
class BadgesChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;
    use HasBroadcastEventId;

    public function __construct(?string $eventId = null)
    {
        $this->initializeBroadcastEventId($eventId);
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        // Badge and approval counts vary by the authenticated user. Fan out
        // only the content-free ping to each user's own private channel; API
        // reads remain the source of each user's permitted values.
        return User::query()
            ->without('role')
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id'])
            ->map(static fn (User $user): PrivateChannel => new PrivateChannel("badge-user.{$user->hash_id}"))
            ->all();
    }

    public function broadcastAs(): string
    {
        return 'BadgesChanged';
    }

    /** @return array{refresh: true, event_id: string} */
    public function broadcastWith(): array
    {
        return ['refresh' => true, 'event_id' => $this->eventId];
    }
}
