<?php

declare(strict_types=1);

namespace App\Modules\MRP\Events;

use App\Common\Events\BroadcastEventEnvelope;
use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Content-free plan refresh signal; recipients refetch through their own API scope. */
final class MrpPlanChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public string $timestamp;

    public function __construct(
        public string $planHashId,
        public string $salesOrderHashId,
        public string $action,
        public string $version,
        public ?string $actorHashId = null,
        ?string $eventId = null,
        ?string $timestamp = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
        $this->timestamp = $timestamp ?? now()->toISOString();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("mrp.plan.{$this->planHashId}"),
            new PrivateChannel('entity.mrp_plan.visible'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chain.refresh';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return BroadcastEventEnvelope::refresh(
            $this->eventId,
            'mrp_plan',
            $this->planHashId,
            $this->action,
            $this->version,
            $this->actorHashId,
            $this->timestamp,
            [['entity_type' => 'sales_order', 'hash_id' => $this->salesOrderHashId]],
        ) + ['hash_id' => $this->planHashId];
    }
}
