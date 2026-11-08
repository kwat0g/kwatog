<?php

declare(strict_types=1);

namespace App\Modules\CRM\Events;

use App\Common\Events\BroadcastEventEnvelope;
use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use App\Common\Events\Concerns\HasBroadcastEventId;
use App\Common\Events\ToleratesNewerModelState;
use App\Modules\CRM\Models\SalesOrder;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sprint 6 audit §1.7 — Fired by SalesOrderService::confirm() right before
 * the MRP engine runs. Drives a "new SO" pulse on the production dashboard
 * (Chain 1 stage breakdown) and the chain header on the SO detail page.
 */
class SalesOrderConfirmed implements ShouldBroadcast, ShouldDispatchAfterCommit, ToleratesNewerModelState
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;
    use HasBroadcastEventId;

    /** @var array<string, mixed> */
    public readonly array $emailSnapshot;

    /** @param array<string, mixed>|null $emailSnapshot */
    public function __construct(
        public SalesOrder $salesOrder,
        ?array $emailSnapshot = null,
        public ?int $actorUserId = null,
        ?string $eventId = null,
        ?string $timestamp = null,
    ) {
        $this->initializeBroadcastEventId($eventId);
        $this->emailSnapshot = $emailSnapshot ?? self::snapshotForEmail($salesOrder);
        $this->timestamp = $timestamp ?? now()->toISOString();
    }

    public string $timestamp;

    /** @return array<string, mixed> */
    public static function snapshotForEmail(SalesOrder $salesOrder): array
    {
        $salesOrder->loadMissing(['customer:id,name,email,contact_person', 'items.product:id,name,part_number']);
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return [
            'customer_email' => $salesOrder->customer?->email,
            'customer_name' => $salesOrder->customer?->contact_person ?: ($salesOrder->customer?->name ?: 'Customer'),
            'so_number' => (string) $salesOrder->so_number,
            'date' => $salesOrder->date?->toDateString(),
            'subtotal' => (string) $salesOrder->subtotal,
            'vat_amount' => (string) $salesOrder->vat_amount,
            'total_amount' => (string) $salesOrder->total_amount,
            'payment_terms_days' => $salesOrder->payment_terms_days,
            'delivery_terms' => $salesOrder->delivery_terms,
            'items' => $salesOrder->items->map(static fn ($item): array => [
                'name' => $item->product?->name ?: 'Item',
                'part_number' => $item->product?->part_number,
                'quantity' => (string) $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'total' => (string) $item->total,
            ])->all(),
            'sales_order_hash_id' => (string) $salesOrder->hash_id,
            'portal_url' => $base.'/portal/customer/orders/'.$salesOrder->hash_id,
        ];
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('production.dashboard')];
    }

    public function broadcastAs(): string
    {
        return 'sales_order.confirmed';
    }

    public function broadcastWith(): array
    {
        $updatedAt = $this->salesOrder->updated_at;
        $version = $updatedAt instanceof DateTimeInterface
            ? Carbon::parse($updatedAt)->utc()->toIso8601String()
            : $this->timestamp;

        $actorHashId = $this->actorUserId === null
            ? null
            : (string) app('hashids')->encode($this->actorUserId);

        return BroadcastEventEnvelope::refresh(
            eventId: $this->eventId,
            entityType: 'sales_order',
            entityId: $this->salesOrder->hash_id,
            action: 'status_changed',
            version: $version,
            actorId: $actorHashId,
            timestamp: $this->timestamp,
        );
    }
}
