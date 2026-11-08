<?php

declare(strict_types=1);

namespace App\Modules\Production\Events;

use App\Common\Events\BroadcastEventEnvelope;
use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use App\Common\Events\Concerns\HasBroadcastEventId;
use App\Common\Events\ToleratesNewerModelState;
use App\Modules\Production\Models\WorkOrder;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast lifecycle transitions to dashboards and assigned workers' own
 * self-service channels; child transitions also refresh the parent's private
 * readiness channel. ChainStepAdvanced refreshes ERP record and list viewers.
 */
class WorkOrderStatusChanged implements ShouldBroadcast, ShouldDispatchAfterCommit, ToleratesNewerModelState
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;
    use HasBroadcastEventId;

    public function __construct(
        public WorkOrder $workOrder,
        public string $from,
        public string $to,
        public ?string $reason = null,
        public array $recipientUserHashIds = [],
        ?string $eventId = null,
        public ?string $actorHashId = null,
        ?string $timestamp = null,
        public ?string $parentWorkOrderHashId = null,
    ) {
        $this->initializeBroadcastEventId($eventId);
        $this->timestamp = $timestamp ?? now()->toISOString();
    }

    public string $timestamp;

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('production.wo.'.$this->workOrder->hash_id),
            new PrivateChannel('production.dashboard'),
        ];

        $parentHashId = $this->parentWorkOrderHashId ?? $this->workOrder->parent()->first()?->hash_id;
        if ($parentHashId !== null) {
            $channels[] = new PrivateChannel('chain.work_order.'.$parentHashId);
        }

        foreach (array_unique($this->recipientUserHashIds) as $userHashId) {
            if (is_string($userHashId) && $userHashId !== '') {
                $channels[] = new PrivateChannel("production.operator.{$userHashId}");
            }
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'work_order.status_changed';
    }

    public function broadcastWith(): array
    {
        $updatedAt = $this->workOrder->updated_at;
        $version = $updatedAt instanceof DateTimeInterface
            ? Carbon::parse($updatedAt)->utc()->toIso8601String()
            : $this->timestamp;

        return BroadcastEventEnvelope::refresh(
            eventId: $this->eventId,
            entityType: 'work_order',
            entityId: $this->workOrder->hash_id,
            action: 'status_changed',
            version: $version,
            actorId: $this->actorHashId,
            timestamp: $this->timestamp,
            related: $this->parentWorkOrderHashId === null ? [] : [[
                'entity_type' => 'work_order',
                'hash_id' => $this->parentWorkOrderHashId,
            ]],
        );
    }
}
