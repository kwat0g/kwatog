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

/** Content-free refresh signal for authorized work-order operation viewers. */
final class WorkOrderOperationChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public function __construct(
        public string $workOrderHashId,
        public string $operationHashId,
        public string $action,
        public string $version,
        public ?string $actorHashId = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("chain.work_order.{$this->workOrderHashId}"),
            new PrivateChannel('entity.work_order.visible'),
            new PrivateChannel('production.dashboard'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chain.refresh';
    }

    /**
     * The dashboard channel has a broader reader scope than work-order detail.
     * Keep the shared wire payload free of record identifiers and business data.
     * Each authorized subscriber already knows which active query to refetch.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['refresh' => true, 'event_id' => $this->eventId];
    }
}
