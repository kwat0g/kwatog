<?php

declare(strict_types=1);

namespace App\Modules\MRP\Listeners;

use App\Modules\MRP\Jobs\RunAutomaticMrpJob;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionStage;
use App\Modules\Quality\Events\InspectionFailed;

/**
 * A batch that fails outgoing QC can no longer fill its order line. MRP
 * already subtracts failed outgoing output from the line; this re-plans that
 * sales order as soon as the result is final, so the replacement WO does not
 * wait for the next stock movement, the daily run, or the NCR's CAPA.
 */
class QueueMrpOnOutgoingInspectionFailed
{
    public function handle(InspectionFailed $event): void
    {
        $inspection = $event->inspection;
        if ($inspection->stage !== InspectionStage::Outgoing || ! $inspection->work_order_output_id) {
            return;
        }

        $workOrder = WorkOrderOutput::query()->with('workOrder')->find($inspection->work_order_output_id)?->workOrder;
        if (! $workOrder?->coversSalesOrderLine()) {
            return;
        }

        RunAutomaticMrpJob::dispatch([(int) $workOrder->sales_order_id], 'outgoing_qc_failed');
    }
}
