<?php

declare(strict_types=1);

namespace App\Modules\Production\Observers;

use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderCrew;
use App\Modules\Production\Models\WorkOrderCrewMember;
use App\Modules\Production\Services\WorkOrderAssignmentRealtimeService;
use Illuminate\Database\Eloquent\Model;

/** Refreshes every parent work-order view after crew or crew-member writes. */
final class WorkOrderAssignmentRealtimeObserver
{
    public function created(Model $record): void
    {
        $this->stage($record);
    }

    public function updated(Model $record): void
    {
        $this->stage($record);
    }

    public function deleted(Model $record): void
    {
        $this->stage($record);
    }

    private function stage(Model $record): void
    {
        $employeeIds = match (true) {
            $record instanceof WorkOrderCrew => $record->members()->pluck('employee_id')->map(fn ($id) => (int) $id)->all(),
            $record instanceof WorkOrderCrewMember => array_values(array_unique(array_filter([
                (int) $record->employee_id,
                (int) $record->getOriginal('employee_id'),
            ]))),
            default => [],
        };

        $workOrder = match (true) {
            $record instanceof WorkOrderCrew => $record->workOrder()->first(),
            $record instanceof WorkOrderCrewMember => $record->crew()->first()?->workOrder()->first(),
            default => null,
        };

        if ($workOrder instanceof WorkOrder) {
            app(WorkOrderAssignmentRealtimeService::class)->stage($workOrder, 'updated', $employeeIds);
        }
    }
}
