<?php

declare(strict_types=1);

namespace App\Modules\Production\Events;

use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sprint 6 — Task 55. Broadcast on output recording.
 *
 * Channels:
 *  - private("production.wo.{wo_hash_id}")    — per-WO subscribers
 *  - private("production.dashboard")          — plant-wide live dashboard
 *
 * Reverb channel auth lives in routes/channels.php (added in this task).
 */
class WorkOrderOutputRecorded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public WorkOrder $workOrder,
        public WorkOrderOutput $output,
    ) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('production.wo.' . $this->workOrder->hash_id),
            new PrivateChannel('production.dashboard'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'output.recorded';
    }

    public function broadcastWith(): array
    {
        return [
            'wo_id'                 => $this->workOrder->hash_id,
            'wo_number'             => $this->workOrder->wo_number,
            'output_id'             => $this->output->hash_id,
            'good_count'            => (int) $this->output->good_count,
            'reject_count'          => (int) $this->output->reject_count,
            'total_quantity_produced' => (int) $this->workOrder->quantity_produced,
            'total_quantity_good'     => (int) $this->workOrder->quantity_good,
            'total_quantity_rejected' => (int) $this->workOrder->quantity_rejected,
            'scrap_rate'            => (string) $this->workOrder->scrap_rate,
            'recorded_at'           => optional($this->output->recorded_at)->toIso8601String(),
        ];
    }
}
