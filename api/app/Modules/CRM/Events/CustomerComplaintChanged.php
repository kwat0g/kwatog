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

/** Content-free complaint signal; every recipient refetches through its authorized API. */
final class CustomerComplaintChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;
    public string $timestamp;

    public function __construct(
        public string $complaintHashId,
        public string $customerHashId,
        public string $action,
        public string $version,
        public ?int $actorId = null,
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
            new PrivateChannel("customer_complaint.{$this->complaintHashId}"),
            new PrivateChannel('entity.customer_complaint.visible'),
            new PrivateChannel("customer.complaint.{$this->customerHashId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'crm.complaint.changed';
    }

    /** @return array{refresh: true, event_id: string, timestamp: string, version: string, action: string} */
    public function broadcastWith(): array
    {
        return [
            'refresh' => true,
            'event_id' => $this->eventId,
            'timestamp' => $this->timestamp,
            'version' => $this->version,
            'action' => $this->action,
        ];
    }
}
