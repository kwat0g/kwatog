<?php

declare(strict_types=1);

namespace App\Modules\CRM\Events;

use App\Common\Events\ToleratesNewerModelState;
use App\Modules\CRM\Models\SalesOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sprint 6 audit §1.7 — Fired by SalesOrderService::confirm() right before
 * the MRP engine runs. Drives a "new SO" pulse on the production dashboard
 * (Chain 1 stage breakdown) and the chain header on the SO detail page.
 */
class SalesOrderConfirmed implements ShouldBroadcast, ToleratesNewerModelState
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @var array<string, mixed> */
    public readonly array $emailSnapshot;

    /** @param array<string, mixed>|null $emailSnapshot */
    public function __construct(public SalesOrder $salesOrder, ?array $emailSnapshot = null)
    {
        $this->emailSnapshot = $emailSnapshot ?? self::snapshotForEmail($salesOrder);
    }

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
        $this->salesOrder->loadMissing('customer:id,name');
        return [
            'so_id'        => $this->salesOrder->hash_id,
            'so_number'    => $this->salesOrder->so_number,
            'total_amount' => (string) $this->salesOrder->total_amount,
            'customer'     => $this->salesOrder->customer ? [
                'id'   => $this->salesOrder->customer->hash_id,
                'name' => $this->salesOrder->customer->name,
            ] : null,
        ];
    }
}
