<?php

declare(strict_types=1);

namespace App\Modules\Production\Events;

use App\Modules\Production\Models\WorkOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Series C — Task C1. Fired AFTER WorkOrderService::complete() commits and
 * before the listener cascade (TriggerOutgoingQC, etc.). Distinct from
 * WorkOrderStatusChanged (which fires for every transition) so consumers
 * can subscribe to "WO is done" specifically without filtering.
 */
class WorkOrderCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(public WorkOrder $workOrder) {}
}
