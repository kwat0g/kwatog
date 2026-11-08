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

/** Content-free refresh for a historical production report and its work order. */
final class LateProductionReportChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public string $timestamp;

    /** @param list<string> $recipientUserHashIds */
    public function __construct(
        public string $workOrderHashId,
        public string $reportHashId,
        public string $action,
        public string $version,
        public ?string $actorHashId = null,
        public array $recipientUserHashIds = [],
        ?string $eventId = null,
        ?string $timestamp = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
        $this->timestamp = $timestamp ?? now()->toISOString();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel("chain.work_order.{$this->workOrderHashId}"),
            new PrivateChannel('entity.work_order.visible'),
            new PrivateChannel('entity.late_production_report.visible'),
        ];

        foreach (array_unique($this->recipientUserHashIds) as $userHashId) {
            if (is_string($userHashId) && $userHashId !== '') {
                $channels[] = new PrivateChannel("production.operator.{$userHashId}");
            }
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'production.late-report.changed';
    }

    /**
     * These same-work-order audiences can have different API row scopes. Keep
     * the wire payload to an opaque signal; each viewer refetches its own API.
     *
     * @return array{refresh: true, event_id: string}
     */
    public function broadcastWith(): array
    {
        return ['refresh' => true, 'event_id' => $this->eventId];
    }
}
