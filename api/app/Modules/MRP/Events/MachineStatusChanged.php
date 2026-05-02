<?php

declare(strict_types=1);

namespace App\Modules\MRP\Events;

use App\Modules\MRP\Models\Machine;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sprint 6 — Task 56. Fired by MachineService::transitionStatus().
 * The breakdown listener (HandleMachineBreakdown) listens here and pauses
 * the active WO, opens a downtime row, and notifies maintenance.
 */
class MachineStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Machine $machine,
        public string $from,
        public string $to,
        public ?string $reason = null,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('production.machine.' . $this->machine->hash_id),
            new PrivateChannel('production.dashboard'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'machine.status_changed';
    }

    public function broadcastWith(): array
    {
        return [
            'machine_id'   => $this->machine->hash_id,
            'machine_code' => $this->machine->machine_code,
            'from'         => $this->from,
            'to'           => $this->to,
            'reason'       => $this->reason,
        ];
    }
}
