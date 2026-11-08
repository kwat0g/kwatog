<?php

declare(strict_types=1);

namespace App\Modules\Production\Observers;

use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderSchedulingDiagnosticRealtimeService;

/** Covers API, scheduler, job, and import writes to scheduler diagnostics. */
final class WorkOrderSchedulingDiagnosticRealtimeObserver
{
    public function updated(WorkOrder $workOrder): void
    {
        if (! $workOrder->wasChanged('scheduling_diagnostic')) {
            return;
        }

        app(WorkOrderSchedulingDiagnosticRealtimeService::class)->stage($workOrder);
    }
}
