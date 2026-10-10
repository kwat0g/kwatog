<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Attendance\Models\Shift;
use App\Modules\HR\Models\Employee;
use App\Modules\Production\Services\WorkOrderAssignmentService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ShiftAssignmentService
{
    /**
     * Bulk-assign a shift to all employees in a department effective from a date.
     * Closes any open assignments for those employees on (effectiveDate - 1).
     *
     * @return array{count: int, shift_id: int, department_id: int}
     */
    public function bulkAssign(int $departmentId, int $shiftId, string $effectiveDate, ?string $endDate = null, ?array $workingDays = null): array
    {
        return DB::transaction(function () use ($departmentId, $shiftId, $effectiveDate, $endDate, $workingDays) {
            $effective = Carbon::parse($effectiveDate);
            $this->assertValidRange($effective, $endDate);
            $this->assertWorkingDays($workingDays);
            $employees = Employee::query()
                ->where('department_id', $departmentId)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');

            $count = 0;
            foreach ($employees as $eid) {
                $this->replaceForEmployee((int) $eid, $shiftId, $effective, $endDate, $workingDays);
                $count++;
            }

            app(WorkOrderAssignmentService::class)->assertCalendarChangesSafe($employees->map(fn ($id) => (int) $id)->all());

            foreach ($employees as $employeeId) {
                app(WorkOrderAssignmentService::class)->notifyStaffingChange((int) $employeeId, 'calendar-'.$shiftId.'-'.$effectiveDate, 'HR shift assignment changed. Review the saved WO crew and schedule.');
            }

            return ['count' => $count, 'shift_id' => $shiftId, 'department_id' => $departmentId];
        });
    }

    /**
     * Assign a single employee to a shift, closing any open assignments.
     */
    public function assignToEmployee(int $employeeId, int $shiftId, string $effectiveDate, ?string $endDate = null, ?array $workingDays = null): void
    {
        DB::transaction(function () use ($employeeId, $shiftId, $effectiveDate, $endDate, $workingDays) {
            $effective = Carbon::parse($effectiveDate);
            $this->assertValidRange($effective, $endDate);
            $this->assertWorkingDays($workingDays);
            Employee::query()->lockForUpdate()->findOrFail($employeeId);
            $this->replaceForEmployee($employeeId, $shiftId, $effective, $endDate, $workingDays);
            app(WorkOrderAssignmentService::class)->assertCalendarChangesSafe([$employeeId]);
            app(WorkOrderAssignmentService::class)->notifyStaffingChange($employeeId, 'calendar-'.$shiftId.'-'.$effectiveDate, 'HR shift assignment changed. Review the saved WO crew and schedule.');
        });
    }

    /**
     * The assignment whose [effective_date, end_date|∞] interval contains $date
     * (newest effective_date wins), or null.
     */
    public function assignmentFor(Employee $employee, CarbonInterface $date): ?EmployeeShiftAssignment
    {
        if ($employee->relationLoaded('shiftAssignments')) {
            return $employee->shiftAssignments->filter(fn ($assignment) => $assignment->effective_date->toDateString() <= $date->toDateString()
                && (! $assignment->end_date || $assignment->end_date->toDateString() >= $date->toDateString()))
                ->sortByDesc(fn ($assignment) => $assignment->effective_date->toDateString().sprintf('%020d', $assignment->id))->first();
        }

        return EmployeeShiftAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('effective_date', '<=', $date->toDateString())
            ->where(function ($q) use ($date) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $date->toDateString());
            })
            ->orderByDesc('effective_date')
            ->first();
    }

    /** Resolve the current shift for an employee on a given date. */
    public function current(Employee $employee, CarbonInterface $date): ?Shift
    {
        return $this->assignmentFor($employee, $date)?->shift;
    }

    /**
     * Whether $date is a working day for $employee. Follows the assignment's
     * `working_days` (ISO weekdays 1–7). A missing attribute, null, empty value or
     * un-cast JSON string that cannot be read falls back to the long-standing
     * default: Monday–Saturday work, Sunday rest.
     */
    public function isWorkingDay(Employee $employee, CarbonInterface $date): bool
    {
        $days = $this->assignmentFor($employee, $date)?->working_days;
        if (is_string($days)) {
            $days = json_decode($days, true);
        }

        $iso = $date->dayOfWeekIso;

        return is_array($days) && $days !== []
            ? in_array($iso, $days, true)
            : $iso !== 7;
    }

    private function replaceForEmployee(int $employeeId, int $shiftId, Carbon $effective, ?string $endDate, ?array $workingDays): void
    {
        if (! Shift::query()->whereKey($shiftId)->where('is_active', true)->exists()) {
            throw new BusinessRuleException('Only active shifts can be assigned.');
        }

        if (Attendance::withTrashed()->where('employee_id', $employeeId)
            ->whereDate('date', '>=', $effective->toDateString())->when($endDate, fn ($query) => $query->whereDate('date', '<=', $endDate))
            ->whereHas('punches')->exists()) {
            throw new BusinessRuleException('Recorded punch history uses this shift assignment. Choose a future effective date.');
        }
        $newStart = $effective->copy()->startOfDay();
        $newEnd = $endDate !== null ? Carbon::parse($endDate)->startOfDay() : null;
        $assignments = EmployeeShiftAssignment::query()
            ->where('employee_id', $employeeId)
            ->orderBy('effective_date')
            ->lockForUpdate()
            ->get();

        foreach ($assignments as $assignment) {
            $existingStart = Carbon::parse($assignment->effective_date)->startOfDay();
            $existingEnd = $assignment->end_date !== null
                ? Carbon::parse($assignment->end_date)->startOfDay()
                : null;
            $overlaps = ($existingEnd === null || $existingEnd->gte($newStart))
                && ($newEnd === null || $existingStart->lte($newEnd));
            if (! $overlaps) {
                continue;
            }

            if ($existingStart->lt($newStart)) {
                $assignment->update(['end_date' => $newStart->copy()->subDay()->toDateString()]);

                continue;
            }

            throw new BusinessRuleException(sprintf(
                'This employee already has a shift starting %s. End or remove it before assigning another.',
                $existingStart->format('F j, Y'),
            ));
        }

        EmployeeShiftAssignment::create([
            'employee_id' => $employeeId,
            'shift_id' => $shiftId,
            'effective_date' => $newStart->toDateString(),
            'end_date' => $newEnd?->toDateString(),
            'working_days' => $workingDays,
            'created_at' => now(),
        ]);
    }

    public function configureWorkingDays(EmployeeShiftAssignment $assignment, array $workingDays): EmployeeShiftAssignment
    {
        $this->assertWorkingDays($workingDays);

        return DB::transaction(function () use ($assignment, $workingDays) {
            Employee::query()->lockForUpdate()->findOrFail($assignment->employee_id);
            $locked = EmployeeShiftAssignment::query()->lockForUpdate()->findOrFail($assignment->id);
            $historical = Attendance::withTrashed()->where('employee_id', $locked->employee_id)
                ->whereDate('date', '>=', $locked->effective_date)->when($locked->end_date, fn ($query) => $query->whereDate('date', '<=', $locked->end_date))
                ->whereHas('punches')->get(['date']);
            if ($historical->contains(fn ($attendance) => ! in_array($attendance->date->dayOfWeekIso, $workingDays, true))) {
                throw new BusinessRuleException('Recorded punch history uses these working days. Create a future shift assignment instead.');
            }
            $locked->update(['working_days' => $workingDays]);
            app(WorkOrderAssignmentService::class)->assertCalendarChangesSafe([(int) $locked->employee_id]);
            app(WorkOrderAssignmentService::class)->notifyStaffingChange((int) $locked->employee_id, 'days-'.$locked->id.'-'.sha1(json_encode($workingDays)), 'HR working days changed. Review the saved WO crew and schedule.');

            return $locked;
        });
    }

    private function assertWorkingDays(?array $workingDays): void
    {
        if ($workingDays === null) {
            return;
        }
        if (! array_is_list($workingDays) || $workingDays === [] || count($workingDays) > 7 || count(array_unique($workingDays)) !== count($workingDays)) {
            throw new BusinessRuleException('Working days must contain unique ISO weekdays from 1 to 7.');
        }
        foreach ($workingDays as $day) {
            if (! is_int($day) || $day < 1 || $day > 7) {
                throw new BusinessRuleException('Working days must contain unique ISO weekdays from 1 to 7.');
            }
        }
    }

    private function assertValidRange(Carbon $effective, ?string $endDate): void
    {
        if ($endDate !== null && Carbon::parse($endDate)->startOfDay()->lt($effective->copy()->startOfDay())) {
            throw new BusinessRuleException('Shift assignment end date must be on or after its effective date.');
        }
    }
}
