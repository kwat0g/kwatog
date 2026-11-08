<?php

declare(strict_types=1);

namespace App\Modules\B2B\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Content-free delivery-schedule refresh signal; clients refetch their authorized API. */
final class B2bDeliveryScheduleChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public string $timestamp;

    public function __construct(
        public string $hashId,
        public string $action,
        public string $version,
        public ?string $customerHashId = null,
        public ?string $vendorHashId = null,
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
        $channels = [
            new PrivateChannel('entity.delivery_schedule.visible'),
            new PrivateChannel("delivery-schedule.{$this->hashId}"),
        ];

        if ($this->customerHashId !== null) {
            $channels[] = new PrivateChannel("customer.delivery-schedule.{$this->customerHashId}");
        }

        if ($this->vendorHashId !== null) {
            $channels[] = new PrivateChannel("supplier.vendor.{$this->vendorHashId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'b2b.delivery-schedule.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => 'delivery_schedule',
            'hash_id' => $this->hashId,
            'action' => $this->action,
            'version' => $this->version,
            'actor_id' => $this->actorHashId,
            'timestamp' => $this->timestamp,
        ];
    }
}
