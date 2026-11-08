<?php

declare(strict_types=1);

namespace App\Modules\MRP\Events;

use App\Modules\MRP\Models\Machine;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Internal domain event for machine lifecycle side effects. The standard
 * MachineChanged refresh signal owns all machine UI broadcasts.
 */
class MachineStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Machine $machine,
        public string $from,
        public string $to,
        public ?string $reason = null,
    ) {}
}
