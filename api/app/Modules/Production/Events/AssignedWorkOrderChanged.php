<?php

declare(strict_types=1);

namespace App\Modules\Production\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Content-free linked-readiness refresh for authorized work-order viewers and assignees. */
final class AssignedWorkOrderChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    /** @param list<string> $recipientUserHashIds */
    public function __construct(
        public array $recipientUserHashIds,
        public ?string $workOrderHashId = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = $this->workOrderHashId === null
            ? []
            : [new PrivateChannel("chain.work_order.{$this->workOrderHashId}")];

        foreach (array_unique($this->recipientUserHashIds) as $userHashId) {
            if (is_string($userHashId) && $userHashId !== '') {
                $channels[] = new PrivateChannel("production.operator.{$userHashId}");
            }
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'assigned.work_order.changed';
    }

    /** @return array{refresh: true, event_id: string} */
    public function broadcastWith(): array
    {
        return ['refresh' => true, 'event_id' => $this->eventId];
    }
}
