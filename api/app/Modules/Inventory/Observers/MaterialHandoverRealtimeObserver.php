<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Observers;

use App\Modules\Inventory\Models\MaterialHandover;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\AssignedWorkOrderRefreshService;

/** Refreshes assigned workers when linked Warehouse handover readiness changes. */
final class MaterialHandoverRealtimeObserver
{
    public function created(MaterialHandover $handover): void
    {
        $this->stage($handover);
    }

    public function updated(MaterialHandover $handover): void
    {
        $this->stage($handover);
    }

    public function deleted(MaterialHandover $handover): void
    {
        $this->stage($handover);
    }

    private function stage(MaterialHandover $handover): void
    {
        $movement = StockMovement::query()->find($handover->stock_movement_id);
        if (! $movement) {
            return;
        }

        $workOrderId = match ($movement->reference_type) {
            'work_order', 'WorkOrder' => (int) $movement->reference_id,
            'material_issue_slip' => (int) (MaterialIssueSlip::query()->whereKey($movement->reference_id)->value('work_order_id') ?? 0),
            default => 0,
        };

        if ($workOrderId > 0 && ($workOrder = WorkOrder::query()->find($workOrderId))) {
            app(AssignedWorkOrderRefreshService::class)->stage($workOrder);
        }
    }
}
