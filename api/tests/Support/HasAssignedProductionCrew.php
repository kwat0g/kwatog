<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\ShiftAssignmentService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderCrew;
use App\Modules\Production\Services\WorkOrderAssignmentService;

trait HasAssignedProductionCrew
{
    protected function assignProductionCrew(WorkOrder $workOrder, User $actor, ?int $ncrId = null): WorkOrderCrew
    {
        if ($actor->role?->slug !== 'employee') {
            $existing = app(WorkOrderAssignmentService::class)->currentCrew($workOrder, $ncrId);
            if ($existing) {
                return $existing;
            }
            $actor = User::factory()->create();
        }
        if (! $actor->employee_id) {
            $actor->forceFill(['employee_id' => Employee::factory()->create()->id, 'is_active' => true])->save();
        }

        $department = Department::firstOrCreate(['code' => 'PROD'], ['name' => 'Production', 'is_active' => true]);
        $actor->employee()->firstOrFail()->forceFill(['department_id' => $department->id, 'status' => 'active'])->save();

        $schedule = app(WorkOrderAssignmentService::class)->schedule($workOrder, $ncrId);
        $coverageStart = $schedule?->scheduled_start ?? ($ncrId ? now() : ($workOrder->planned_start ?? now()));
        $coverageEnd = $schedule?->scheduled_end ?? ($workOrder->planned_end ?? $coverageStart->copy()->addHours(8));
        $startTime = $coverageStart->copy()->timezone(config('app.timezone'))->format('H:i:s');
        $endTime = $coverageEnd->copy()->timezone(config('app.timezone'))->format('H:i:s');
        if (! app(ShiftAssignmentService::class)->current($actor->employee()->firstOrFail(), $coverageStart)) {
            $shift = Shift::firstOrCreate(['name' => "Regression production shift {$startTime}-{$endTime}"], [
                'start_time' => $startTime, 'end_time' => $endTime,
                'is_night_shift' => $endTime <= $startTime, 'is_active' => true,
            ]);
            app(ShiftAssignmentService::class)->assignToEmployee(
                $actor->employee_id, $shift->id, $coverageStart->toDateString(), null, [1, 2, 3, 4, 5, 6, 7],
            );
        }

        $shift = app(ShiftAssignmentService::class)->current($actor->employee()->firstOrFail(), $coverageStart);
        if ($shift) {
            Attendance::firstOrCreate(['employee_id' => $actor->employee_id, 'date' => $coverageStart->toDateString()],
                ['shift_id' => $shift->id, 'time_in' => $coverageStart, 'status' => 'present']);
        }
        $current = app(WorkOrderAssignmentService::class)->currentCrew($workOrder, $ncrId);
        if ($current) {
            return $current;
        }

        $crew = $workOrder->crews()->create([
            'rework_ncr_id' => $ncrId,
            'recorder_employee_id' => $actor->employee_id,
            'assigned_by' => $actor->id,
            'reason' => 'Assigned crew for focused production regression.',
            'assigned_at' => now(),
        ]);
        $crew->members()->create([
            'employee_id' => $actor->employee_id,
            'employee_snapshot' => app(WorkOrderAssignmentService::class)->employeeOption($actor->employee()->firstOrFail()),
        ]);

        return $crew;
    }

    protected function outputForCrew(WorkOrder $workOrder, array $data, User $actor, ?int $ncrId = null): array
    {
        $crew = $this->assignProductionCrew($workOrder, $actor, $ncrId);

        return $data + ['crew_id' => $crew->id, 'recording_reason' => 'Production regression recording.'];
    }
}
