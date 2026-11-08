<?php

declare(strict_types=1);

namespace App\Modules\CRM\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Customer-account scoped signal containing no product or pricing data. */
final class CustomerCatalogChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;
    public string $timestamp;

    /** @param list<string> $customerHashIds */
    public function __construct(
        public array $customerHashIds,
        public string $action,
        public string $version,
        ?string $eventId = null,
        ?string $timestamp = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
        $this->timestamp = $timestamp ?? now()->toISOString();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(
            static fn (string $customerHashId): PrivateChannel => new PrivateChannel("customer.catalog.{$customerHashId}"),
            array_values(array_unique($this->customerHashIds)),
        );
    }

    public function broadcastAs(): string
    {
        return 'customer.catalog.changed';
    }

    /** @return array{refresh: true, event_id: string, timestamp: string, action: string, version: string} */
    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'timestamp' => $this->timestamp,
            'action' => $this->action,
            'version' => $this->version,
        ];
    }
}
