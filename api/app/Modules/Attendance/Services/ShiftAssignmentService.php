<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Attendance\Models\Shift;
use App\Modules\HR\Models\Employee;
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
    public function bulkAssign(int $departmentId, int $shiftId, string $effectiveDate, ?string $endDate = null): array
    {
        return DB::transaction(function () use ($departmentId, $shiftId, $effectiveDate, $endDate) {
            $effective = Carbon::parse($effectiveDate);
            $this->assertValidRange($effective, $endDate);
            $employees = Employee::query()
                ->where('department_id', $departmentId)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');

            $count = 0;
            foreach ($employees as $eid) {
                $this->replaceForEmployee((int) $eid, $shiftId, $effective, $endDate);
                $count++;
            }

            return ['count' => $count, 'shift_id' => $shiftId, 'department_id' => $departmentId];
        });
    }

    /**
     * Assign a single employee to a shift, closing any open assignments.
     */
    public function assignToEmployee(int $employeeId, int $shiftId, string $effectiveDate, ?string $endDate = null): void
    {
        DB::transaction(function () use ($employeeId, $shiftId, $effectiveDate, $endDate) {
            $effective = Carbon::parse($effectiveDate);
            $this->assertValidRange($effective, $endDate);
            Employee::query()->lockForUpdate()->findOrFail($employeeId);
            $this->replaceForEmployee($employeeId, $shiftId, $effective, $endDate);
        });
    }

    /**
     * The assignment whose [effective_date, end_date|∞] interval contains $date
     * (newest effective_date wins), or null.
     */
    public function assignmentFor(Employee $employee, CarbonInterface $date): ?EmployeeShiftAssignment
    {
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
        return $this->assignmentFor($employee, $date)?->shift()->first();
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

    private function replaceForEmployee(int $employeeId, int $shiftId, Carbon $effective, ?string $endDate): void
    {
        if (! Shift::query()->whereKey($shiftId)->where('is_active', true)->exists()) {
            throw new BusinessRuleException('Only active shifts can be assigned.');
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
                'The new shift assignment overlaps an existing future assignment beginning %s. End or remove that assignment first.',
                $existingStart->toDateString(),
            ));
        }

        EmployeeShiftAssignment::create([
            'employee_id'    => $employeeId,
            'shift_id'       => $shiftId,
            'effective_date' => $newStart->toDateString(),
            'end_date'       => $newEnd?->toDateString(),
            'created_at'     => now(),
        ]);
    }

    private function assertValidRange(Carbon $effective, ?string $endDate): void
    {
        if ($endDate !== null && Carbon::parse($endDate)->startOfDay()->lt($effective->copy()->startOfDay())) {
            throw new BusinessRuleException('Shift assignment end date must be on or after its effective date.');
        }
    }
}
