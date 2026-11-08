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

/** Refresh-only signal for internal pricing reviewers and one customer account. */
final class PriceRequestChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;
    public string $timestamp;

    public function __construct(
        public string $requestHashId,
        public string $customerHashId,
        public string $action,
        public string $version,
        public ?string $actorHashId = null,
        public ?string $agreementHashId = null,
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
            new PrivateChannel("crm.price-request.{$this->requestHashId}"),
            new PrivateChannel('entity.price_request.visible'),
            new PrivateChannel("customer.price-request.{$this->customerHashId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'crm.price-request.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $payload = [
            'refresh' => true,
            'event_id' => $this->eventId,
            'timestamp' => $this->timestamp,
            'entity_type' => 'price_request',
            'entity_id' => $this->requestHashId,
            'action' => $this->action,
            'version' => $this->version,
            'actor_id' => $this->actorHashId,
        ];

        if ($this->agreementHashId !== null) {
            $payload['related'] = [[
                'entity_type' => 'price_agreement',
                'hash_id' => $this->agreementHashId,
            ]];
        }

        return $payload;
    }
}
