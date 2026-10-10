<?php

declare(strict_types=1);

namespace App\Modules\Production\Observers;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\HR\Models\Employee;
use App\Modules\Production\Services\WorkOrderAssignmentService;
use Illuminate\Database\Eloquent\Model;

class ProductionStaffingObserver
{
    public function saved(Model $record): void
    {
        $fields = $record instanceof Attendance ? ['time_in', 'time_out', 'status', 'shift_id', 'is_manual_entry'] : ['status', 'is_active', 'department_id', 'role_id', 'employee_id', 'start_date', 'end_date', 'half_day_period'];
        if ($record->wasChanged($fields) || ($record->wasRecentlyCreated && $record instanceof Attendance && $record->status?->value !== 'present')) {
            $this->notify($record);
            if ($record->wasChanged('employee_id') && $record->getRawOriginal('employee_id')) {
                app(WorkOrderAssignmentService::class)->notifyStaffingChange((int) $record->getRawOriginal('employee_id'), 'account-unlinked-'.$record->id.'-'.(string) $record->updated_at);
            }
        }
    }

    public function deleted(Model $record): void
    {
        $this->notify($record);
    }

    private function notify(Model $record): void
    {
        $employeeId = $record instanceof Employee ? $record->id : $record->employee_id;
        if ($employeeId) {
            app(WorkOrderAssignmentService::class)->notifyStaffingChange((int) $employeeId,
                sha1($record->getMorphClass().$record->id.json_encode($record->getChanges()).(string) $record->updated_at));
        }
    }
}
