<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Content-free refresh signal for authorized budget readers and approvers. */
final class BudgetChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public string $timestamp;

    public function __construct(
        public ?string $budgetHashId,
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
        $channels = [new PrivateChannel('entity.budget.visible')];
        if ($this->budgetHashId !== null) {
            $channels[] = new PrivateChannel("accounting.budget.{$this->budgetHashId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'accounting.budget.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        // List and approval audiences receive no record ID or finance data.
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => 'budget',
            'action' => $this->action,
            'version' => $this->version,
            'actor_id' => $this->actorHashId,
            'timestamp' => $this->timestamp,
        ];
    }
}
