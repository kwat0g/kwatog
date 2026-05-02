<?php

declare(strict_types=1);

namespace App\Modules\Production\Events;

use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sprint 6 audit §1.7 — Fired by HandleMachineBreakdown after a machine
 * transitions into the breakdown state and the running WO has been paused.
 * Drives the BreakdownAlertCard on the dashboard.
 */
class MachineBreakdownDetected implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param array<int, array{id: string, machine_code: string, name: string}> $candidates */
    public function __construct(
        public Machine $machine,
        public ?WorkOrder $pausedWorkOrder,
        public array $candidates = [],
        public ?string $reason = null,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('production.dashboard'),
            new PrivateChannel('production.machine.' . $this->machine->hash_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'machine.breakdown_detected';
    }

    public function broadcastWith(): array
    {
        return [
            'machine_id'   => $this->machine->hash_id,
            'machine_code' => $this->machine->machine_code,
            'paused_wo'    => $this->pausedWorkOrder ? [
                'id'        => $this->pausedWorkOrder->hash_id,
                'wo_number' => $this->pausedWorkOrder->wo_number,
            ] : null,
            'candidates'   => $this->candidates,
            'reason'       => $this->reason,
        ];
    }
}
