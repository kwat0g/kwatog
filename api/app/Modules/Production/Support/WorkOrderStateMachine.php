<?php

declare(strict_types=1);

namespace App\Modules\Production\Support;

use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Exceptions\IllegalLifecycleTransitionException;
use App\Modules\Production\Models\WorkOrder;

/** The only legal work-order lifecycle transitions. */
final class WorkOrderStateMachine
{
    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        'planned'     => ['confirmed', 'cancelled'],
        'confirmed'   => ['in_progress', 'cancelled'],
        'in_progress' => ['paused', 'completed'],
        'paused'      => ['in_progress', 'cancelled'],
        'completed'   => ['closed'],
        'closed'      => [],
        'cancelled'   => [],
    ];

    /**
     * Validate a requested transition without mutating the aggregate.
     * Persistence remains the responsibility of WorkOrderService's transaction.
     */
    public function assertAllowed(WorkOrder $workOrder, WorkOrderStatus $target): void
    {
        $from = $workOrder->status?->value ?? 'planned';
        if (! in_array($target->value, self::TRANSITIONS[$from] ?? [], true)) {
            throw new IllegalLifecycleTransitionException($from, $target->value);
        }
    }
}
