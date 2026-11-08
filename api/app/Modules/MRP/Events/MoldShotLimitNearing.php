<?php

declare(strict_types=1);

namespace App\Modules\MRP\Events;

use App\Common\Events\BroadcastEventEnvelope;
use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use App\Common\Events\Concerns\HasBroadcastEventId;
use App\Common\Events\ToleratesNewerModelState;
use App\Modules\MRP\Models\Mold;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sprint 6 audit §1.7 — Fired when a mold's current_shot_count crosses
 * the configured warning ratio of max_shots_before_maintenance. Maintenance head should schedule
 * a preventive overhaul before the mold hits 100% (which would auto-flip
 * it to Maintenance status and block new WOs).
 */
class MoldShotLimitNearing implements ShouldBroadcast, ShouldDispatchAfterCommit, ToleratesNewerModelState
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;
    use HasBroadcastEventId;

    public function __construct(public Mold $mold, ?string $eventId = null, ?string $timestamp = null)
    {
        $this->initializeBroadcastEventId($eventId);
        $this->timestamp = $timestamp ?? now()->toISOString();
    }

    public string $timestamp;

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('production.dashboard')];
    }

    public function broadcastAs(): string
    {
        return 'mold.shot_limit_nearing';
    }

    public function broadcastWith(): array
    {
        $updatedAt = $this->mold->updated_at;
        $version = $updatedAt instanceof DateTimeInterface
            ? Carbon::parse($updatedAt)->utc()->toIso8601String()
            : $this->timestamp;

        return BroadcastEventEnvelope::refresh(
            eventId: $this->eventId,
            entityType: 'mold',
            entityId: $this->mold->hash_id,
            action: 'updated',
            version: $version,
            actorId: null,
            timestamp: $this->timestamp,
        );
    }
}
