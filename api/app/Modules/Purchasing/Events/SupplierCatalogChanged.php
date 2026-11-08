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

/** Content-free refresh signal for supplier listing and approved supplier views. */
class SupplierCatalogChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;
    public string $timestamp;

    public function __construct(
        public string $action,
        public string $version,
        /** Single vendor ID for ordinary writes, list for a bulk write. @var string|list<string>|null */
        public string|array|null $vendorHashId = null,
        ?string $eventId = null,
        ?string $timestamp = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
        $this->timestamp = $timestamp ?? now()->toIso8601String();
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('entity.supplier_listing.visible'),
            new PrivateChannel('entity.approved_supplier.visible'),
        ];

        $vendorHashIds = array_values(array_unique(array_filter(
            is_array($this->vendorHashId) ? $this->vendorHashId : [$this->vendorHashId],
        )));

        foreach ($vendorHashIds as $vendorHashId) {
            $channels[] = new PrivateChannel("supplier.vendor.{$vendorHashId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'supplier.catalog.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return BroadcastEventEnvelope::refresh(
            eventId: $this->eventId,
            entityType: 'supplier_catalog',
            entityId: null,
            action: $this->action,
            version: $this->version,
            actorId: null,
            timestamp: $this->timestamp,
        );
    }
}
