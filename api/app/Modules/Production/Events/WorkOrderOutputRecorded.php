<?php

declare(strict_types=1);

namespace App\Modules\Production\Events;

use App\Common\Events\BroadcastEventEnvelope;
use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use App\Common\Events\Concerns\HasBroadcastEventId;
use App\Common\Events\ToleratesNewerModelState;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sprint 6 — Task 55. Broadcast on output recording.
 *
 * Channels include permission-scoped ERP viewers and the active assigned
 * employees' exact-user self-service channels. Every consumer refetches data.
 */
class WorkOrderOutputRecorded implements ShouldBroadcast, ShouldDispatchAfterCommit, ToleratesNewerModelState
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;
    use HasBroadcastEventId;

    /** @param list<string> $recipientUserHashIds */
    public function __construct(
        public WorkOrder $workOrder,
        public WorkOrderOutput $output,
        public array $recipientUserHashIds = [],
        ?string $eventId = null,
        public ?string $actorHashId = null,
        ?string $timestamp = null,
        public ?string $parentWorkOrderHashId = null,
    ) {
        $this->initializeBroadcastEventId($eventId);
        $this->timestamp = $timestamp ?? now()->toISOString();

        $recordedBy = $this->output->getAttribute('recorded_by');
        if ($this->actorHashId === null && is_numeric($recordedBy) && (int) $recordedBy > 0) {
            $this->actorHashId = (string) app('hashids')->encode((int) $recordedBy);
        }

        if ($this->parentWorkOrderHashId === null && $this->workOrder->parent_wo_id !== null) {
            $this->parentWorkOrderHashId = $this->workOrder->parent()->first()?->hash_id;
        }
    }

    public string $timestamp;

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('production.wo.'.$this->workOrder->hash_id),
            new PrivateChannel('entity.work_order.visible'),
            new PrivateChannel('production.dashboard'),
            new PrivateChannel('quality.dashboard'),
        ];

        if ($this->parentWorkOrderHashId !== null) {
            $channels[] = new PrivateChannel('chain.work_order.'.$this->parentWorkOrderHashId);
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
        return 'output.recorded';
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
            action: 'updated',
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
