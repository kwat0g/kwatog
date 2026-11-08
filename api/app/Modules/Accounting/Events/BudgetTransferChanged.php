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

/** Content-free refresh signal for authorized budget-transfer readers/approvers. */
final class BudgetTransferChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public string $timestamp;

    public function __construct(
        public string $transferHashId,
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
            new PrivateChannel("accounting.budget-transfer.{$this->transferHashId}"),
            new PrivateChannel('entity.budget_transfer.visible'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'accounting.budget-transfer.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        // The broad approver channel intentionally receives no record ID or
        // financial fields; each client refetches only its permitted API view.
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => 'budget_transfer',
            'action' => $this->action,
            'version' => $this->version,
            'actor_id' => $this->actorHashId,
            'timestamp' => $this->timestamp,
        ];
    }
}
