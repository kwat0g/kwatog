<?php

declare(strict_types=1);

namespace App\Modules\Production\Events;

use App\Modules\Production\Models\WorkOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sprint 6 audit §1.7 — Broadcast every WO lifecycle transition so the
 * dashboard, the WO detail page, and the schedule view all reflect status
 * changes without a manual refetch.
 */
class WorkOrderStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public WorkOrder $workOrder,
        public string $from,
        public string $to,
        public ?string $reason = null,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('production.wo.' . $this->workOrder->hash_id),
            new PrivateChannel('production.dashboard'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'work_order.status_changed';
    }

    public function broadcastWith(): array
    {
        return [
            'wo_id'     => $this->workOrder->hash_id,
            'wo_number' => $this->workOrder->wo_number,
            'from'      => $this->from,
            'to'        => $this->to,
            'reason'    => $this->reason,
        ];
    }
}
