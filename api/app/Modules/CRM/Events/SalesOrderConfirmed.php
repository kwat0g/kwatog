<?php

declare(strict_types=1);

namespace App\Modules\CRM\Events;

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
class SalesOrderConfirmed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public SalesOrder $salesOrder) {}

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
