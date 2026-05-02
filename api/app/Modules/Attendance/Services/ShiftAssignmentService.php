<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

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
            $employees = Employee::query()->where('department_id', $departmentId)->pluck('id');
            $effective = Carbon::parse($effectiveDate);
            $closeOn   = $effective->copy()->subDay()->toDateString();

            EmployeeShiftAssignment::query()
                ->whereIn('employee_id', $employees)
                ->whereNull('end_date')
                ->update(['end_date' => $closeOn]);

            $count = 0;
            foreach ($employees as $eid) {
                EmployeeShiftAssignment::create([
                    'employee_id'    => $eid,
                    'shift_id'       => $shiftId,
                    'effective_date' => $effective->toDateString(),
                    'end_date'       => $endDate,
                    'created_at'     => now(),
                ]);
                $count++;
            }

            return ['count' => $count, 'shift_id' => $shiftId, 'department_id' => $departmentId];
        });
    }

    /**
     * Resolve the current shift for an employee on a given date.
     * Returns the most recent assignment whose [effective_date, end_date|∞] interval contains $date.
     */
    public function current(Employee $employee, CarbonInterface $date): ?Shift
    {
        $assignment = EmployeeShiftAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('effective_date', '<=', $date->toDateString())
            ->where(function ($q) use ($date) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $date->toDateString());
            })
            ->orderByDesc('effective_date')
            ->first();

        return $assignment?->shift()->first();
    }
}
