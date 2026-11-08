<?php

declare(strict_types=1);

namespace App\Modules\Production\Events;

use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Models\WorkOrder;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sprint 6 audit §1.7 — Fired by HandleMachineBreakdown after a machine
 * transitions into the breakdown state and the running WO has been paused.
 * Its listener creates notifications; MachineChanged owns UI refresh broadcasts.
 */
class MachineBreakdownDetected implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /** @param array<int, array{id: string, machine_code: string, name: string}> $candidates */
    public function __construct(
        public Machine $machine,
        public ?WorkOrder $pausedWorkOrder,
        public array $candidates = [],
        public ?string $reason = null,
    ) {}

}
