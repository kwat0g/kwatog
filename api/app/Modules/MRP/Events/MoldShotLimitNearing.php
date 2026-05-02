<?php

declare(strict_types=1);

namespace App\Modules\MRP\Events;

use App\Modules\MRP\Models\Mold;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sprint 6 audit §1.7 — Fired when a mold's current_shot_count crosses
 * 80% of max_shots_before_maintenance. Maintenance head should schedule
 * a preventive overhaul before the mold hits 100% (which would auto-flip
 * it to Maintenance status and block new WOs).
 */
class MoldShotLimitNearing implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Mold $mold) {}

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
        return [
            'mold_id'                      => $this->mold->hash_id,
            'mold_code'                    => $this->mold->mold_code,
            'name'                         => $this->mold->name,
            'current_shot_count'           => (int) $this->mold->current_shot_count,
            'max_shots_before_maintenance' => (int) $this->mold->max_shots_before_maintenance,
            'shot_percentage'              => (float) $this->mold->shot_percentage,
        ];
    }
}
