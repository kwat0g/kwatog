<?php

declare(strict_types=1);

namespace App\Modules\MRP\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use App\Common\Events\Concerns\HasBroadcastEventId;
use App\Common\Events\ToleratesNewerModelState;
use App\Modules\MRP\Enums\MrpPlanStatus;
use App\Modules\MRP\Models\MrpPlan;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sprint 6 audit §1.7 — Fired by MrpEngineService::runForSalesOrder() once
 * the plan is committed. Drives the dashboard "MRP plan generated" pulse
 * and the SO detail page's chain header refresh.
 */
class MrpPlanGenerated implements ShouldBroadcast, ShouldDispatchAfterCommit, ToleratesNewerModelState
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;
    use HasBroadcastEventId;

    public function __construct(
        public MrpPlan $plan,
        public ?int $sourceRunId = null,
        public ?int $initiatingActorId = null,
        public ?string $reason = null,
        ?string $eventId = null,
    ) {
        $this->initializeBroadcastEventId($eventId);
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('production.dashboard')];
    }

    public function broadcastAs(): string
    {
        return 'mrp.plan_generated';
    }

    public function broadcastWhen(): bool
    {
        $this->plan->refresh();

        return $this->plan->status === MrpPlanStatus::Active;
    }

    public function broadcastWith(): array
    {
        return ['refresh' => true, 'event_id' => $this->eventId];
    }
}
