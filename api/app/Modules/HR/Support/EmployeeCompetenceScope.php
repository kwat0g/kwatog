<?php

declare(strict_types=1);

namespace App\Modules\HR\Support;

use App\Common\Support\DepartmentScope;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeSkill;
use App\Modules\HR\Models\EmployeeTraining;
use Illuminate\Database\Eloquent\Builder;

/**
 * The row-level boundary shared by employee training and skill records.
 *
 * The route permission answers whether an actor may use a surface; this
 * boundary answers which employee rows that actor may use. Hash IDs are only
 * opaque identifiers and must never be treated as an authorization check.
 */
final class EmployeeCompetenceScope
{
    /**
     * Apply the same department-plus-self rule used by employee master reads.
     * Route middleware supplies the relevant view/manage permission, so a
     * caller reaching this helper is already authorized for the surface.
     *
     * @return Builder<Employee>
     */
    public static function employees(Builder $query, ?User $actor): Builder
    {
        return DepartmentScope::apply(
            $query,
            $actor,
            viewAllPermission: 'hr.employees.view_sensitive',
            departmentPermission: null,
            deptColumn: 'department_id',
            selfColumn: 'id',
            selfId: $actor?->employee_id,
        );
    }

    public static function employee(Employee $employee, ?User $actor): Employee
    {
        $query = Employee::query()->whereKey($employee->getKey());
        self::employees($query, $actor);

        return $query->firstOrFail();
    }

    public static function training(EmployeeTraining $record, ?User $actor): EmployeeTraining
    {
        $record->loadMissing('employee');
        abort_unless($record->employee !== null, 404);
        self::employee($record->employee, $actor);

        return $record;
    }

    public static function skill(EmployeeSkill $record, ?User $actor): EmployeeSkill
    {
        $record->loadMissing('employee');
        abort_unless($record->employee !== null, 404);
        self::employee($record->employee, $actor);

        return $record;
    }
}
