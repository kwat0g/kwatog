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

/** Content-free Customer refresh signal; each reader refetches through its API scope. */
final class CustomerChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ?string $customerHashId,
        public string $action,
        public string $version,
        public ?string $actorHashId = null,
        public bool $isBatch = false,
        public bool $includeCustomerProfile = true,
        public string $eventId = '',
        public string $timestamp = '',
    ) {
        $this->eventId = $eventId !== '' ? $eventId : (string) Str::uuid();
        $this->timestamp = $timestamp !== '' ? $timestamp : now()->toISOString();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('entity.customer.visible')];

        if (! $this->isBatch && $this->customerHashId !== null) {
            $channels[] = new PrivateChannel("accounting.customer.{$this->customerHashId}");
            if ($this->includeCustomerProfile) {
                $channels[] = new PrivateChannel("customer.profile.{$this->customerHashId}");
            }
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'accounting.customer.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $payload = [
            'refresh' => true,
            'event_id' => $this->eventId,
            'entity_type' => 'customer',
        ];

        if (! $this->isBatch && $this->customerHashId !== null) {
            $payload['entity_id'] = $this->customerHashId;
        }

        $payload += [
            'action' => $this->action,
            'version' => $this->version,
            'actor_id' => $this->actorHashId,
            'timestamp' => $this->timestamp,
        ];

        return $payload;
    }
}
