<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Events;

use App\Common\Events\BroadcastEventEnvelope;
use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Refresh ping limited to vendors invited to a published RFQ. */
final class SupplierRfqChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    public string $timestamp;

    /** @param list<string> $supplierVendorHashIds */
    public function __construct(
        public string $rfqHashId,
        public string $action,
        public string $version,
        public array $supplierVendorHashIds,
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
            static fn (string $vendorHashId): PrivateChannel => new PrivateChannel("supplier.vendor.{$vendorHashId}"),
            $this->supplierVendorHashIds,
        );
    }

    public function broadcastAs(): string
    {
        return 'supplier.rfq.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            ...BroadcastEventEnvelope::refresh(
                eventId: $this->eventId,
                entityType: 'request_for_quote',
                entityId: $this->rfqHashId,
                action: $this->action,
                version: $this->version,
                actorId: null,
                timestamp: $this->timestamp,
            ),
            // Keep the legacy key because vendor portal bundles may still read it.
            'hash_id' => $this->rfqHashId,
        ];
    }
}
