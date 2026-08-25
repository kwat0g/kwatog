<?php

declare(strict_types=1);

namespace App\Modules\Loans\Policies;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Loans\Models\EmployeeLoan;
use Illuminate\Database\Eloquent\Builder;

/**
 * Server-side row scope for every operational loan read and decision.
 *
 * Permissions answer whether an action is available; this policy answers
 * which employee rows that action may touch. The two checks must stay
 * separate, especially because department_head has loans.approve.
 */
final class LoanAccessPolicy
{
    /**
     * @param Builder<EmployeeLoan> $query
     * @return Builder<EmployeeLoan>
     */
    public function visibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isGlobal($user)) {
            return $query;
        }

        $employeeId = $user->employee_id;
        if ($employeeId === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->role?->slug !== 'department_head') {
            return $query->where('employee_id', $employeeId);
        }

        $departmentId = $this->departmentId($user);

        return $query->where(function (Builder $scope) use ($employeeId, $departmentId): void {
            $scope->where('employee_id', $employeeId);
            if ($departmentId !== null) {
                $scope->orWhereHas('employee', static fn (Builder $employee): Builder => $employee->where('department_id', $departmentId));
            }
        });
    }

    public function canView(User $user, EmployeeLoan $loan): bool
    {
        return $this->canAccessEmployee($user, (int) $loan->employee_id);
    }

    public function canDecide(User $user, EmployeeLoan $loan): bool
    {
        return $this->canAccessEmployee($user, (int) $loan->employee_id);
    }

    public function canViewEmployee(User $user, Employee $employee): bool
    {
        return $this->canAccessEmployee($user, (int) $employee->getKey());
    }

    private function canAccessEmployee(User $user, int $employeeId): bool
    {
        if ($this->isGlobal($user)) {
            return true;
        }

        if ($user->employee_id === null) {
            return false;
        }

        if ((int) $user->employee_id === $employeeId) {
            return true;
        }

        if ($user->role?->slug !== 'department_head') {
            return false;
        }

        $departmentId = $this->departmentId($user);
        if ($departmentId === null) {
            return false;
        }

        return (int) Employee::query()->whereKey($employeeId)->value('department_id') === $departmentId;
    }

    private function isGlobal(User $user): bool
    {
        // These roles are the only seeded company-wide loan operators. Keep
        // this role list explicit; loans.approve alone is not global scope.
        return in_array($user->role?->slug, ['system_admin', 'finance_officer', 'hr_officer'], true);
    }

    private function departmentId(User $user): ?int
    {
        if ($user->employee_id === null) {
            return null;
        }

        $departmentId = Employee::query()->whereKey($user->employee_id)->value('department_id');

        return $departmentId !== null ? (int) $departmentId : null;
    }
}
