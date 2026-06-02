<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Polish Task S2 (real-time) — fired whenever badge-affecting data changes.
 * Carries no payload; clients simply refetch their own permission-scoped
 * counts from GET /dashboards/badges (which is now cache-busted via the
 * version bump in BadgeService::touch()).
 */
class BadgesChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('badges')];
    }

    public function broadcastAs(): string
    {
        return 'BadgesChanged';
    }
}
