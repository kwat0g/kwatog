<?php

declare(strict_types=1);

namespace App\Modules\Loans\Policies;

use App\Common\Models\ApprovalDelegation;
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
 *
 * Ladder (2026-09-10 approval-chain audit):
 *   - system_admin / finance_officer / hr_officer → every loan (company-wide
 *     operators);
 *   - department_head                             → own + their department's;
 *   - chain participants                          → loans waiting on a step
 *     that names one of the caller's roles (production_manager is step 2 of
 *     company_loan; vice_president closes cash_advance step 3 and
 *     company_loan step 4) — without this branch those steps stalled
 *     invisibly: the board hid the card while the badge still counted it;
 *   - everyone else                               → their own loans only.
 */
final class LoanAccessPolicy
{
    /**
     * @param  Builder<EmployeeLoan>  $query
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
            // A user with no employee row has no self rows and no department,
            // but delegation or a chain step can still name their role.
            $stepRoles = $this->chainStepRoles($user);

            return $stepRoles === []
                ? $query->whereRaw('1 = 0')
                : $query->whereHas('approvalRecords', $this->pendingStepScope($stepRoles));
        }

        if ($user->role?->slug !== 'department_head') {
            $stepRoles = $this->chainStepRoles($user);

            return $query->where(function (Builder $scope) use ($employeeId, $stepRoles): void {
                $scope->where('employee_loans.employee_id', $employeeId);
                if ($stepRoles !== []) {
                    $scope->orWhereHas('approvalRecords', $this->pendingStepScope($stepRoles));
                }
            });
        }

        $departmentId = $this->departmentId($user);

        return $query->where(function (Builder $scope) use ($employeeId, $departmentId): void {
            $scope->where('employee_loans.employee_id', $employeeId);
            if ($departmentId !== null) {
                $scope->orWhereHas('employee', static fn (Builder $employee): Builder => $employee->where('department_id', $departmentId));
            }
        });
    }

    public function canView(User $user, EmployeeLoan $loan): bool
    {
        return $this->canAccessEmployee($user, (int) $loan->employee_id)
            || $this->waitsOnCallersStep($user, $loan);
    }

    public function canDecide(User $user, EmployeeLoan $loan): bool
    {
        return $this->canAccessEmployee($user, (int) $loan->employee_id)
            || $this->waitsOnCallersStep($user, $loan);
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

    /**
     * An approval-chain participant must be able to open the loans waiting on
     * their step — you cannot approve what you cannot read. Role match per
     * step is still enforced by ApprovalService::userMayActFor; this only
     * answers row visibility, exactly like the purchase-order policy's
     * chain-participant branch (PS-01b).
     */
    private function waitsOnCallersStep(User $user, EmployeeLoan $loan): bool
    {
        $stepRoles = $this->chainStepRoles($user);
        if ($stepRoles === []) {
            return false;
        }

        return $loan->approvalRecords()
            ->whereIn('role_slug', $stepRoles)
            ->where('action', 'pending')
            ->exists();
    }

    /**
     * @param  list<string>  $stepRoles
     * @return callable(Builder): void
     */
    private function pendingStepScope(array $stepRoles): callable
    {
        return static function (Builder $records) use ($stepRoles): void {
            $records->whereIn('role_slug', $stepRoles)
                ->where('action', 'pending');
        };
    }

    /**
     * The caller's approval-chain roles: the role slug itself plus any active
     * delegation, in step with ApprovalService::userMayActFor.
     *
     * @return list<string>
     */
    private function chainStepRoles(User $user): array
    {
        if ($user->role?->slug === null) {
            return [];
        }

        $roles = [$user->role->slug];

        foreach (ApprovalDelegation::actsForRoles($user->id, now()) as $delegated) {
            $roles[] = $delegated;
        }

        return array_values(array_unique($roles));
    }

    private function isGlobal(User $user): bool
    {
        // These roles are the only seeded company-wide loan operators. Keep
        // this role list explicit; loans.approve alone is not global scope —
        // production_manager and vice_president hold it purely as chain
        // participants and stay scoped to the rows waiting on them.
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
