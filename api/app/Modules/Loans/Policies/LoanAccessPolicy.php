<?php

declare(strict_types=1);

namespace App\Modules\Loans\Policies;

use App\Common\Models\ApprovalDelegation;
use App\Common\Models\ApprovalRecord;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Loans\Models\EmployeeLoan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
 *   - chain participants                          → loans waiting on a
 *     plant-wide step that names one of the caller's roles (production_manager
 *     is step 2 of company_loan; vice_president closes cash_advance step 3 and
 *     company_loan step 4) — the departmental step is deliberately excluded
 *     here so a head's reach stays their own department. Without this branch
 *     those steps stalled invisibly: the board hid the card while the badge
 *     still counted it;
 *   - everyone else                               → their own loans only.
 */
final class LoanAccessPolicy
{
    /**
     * The one loan-chain step that is scoped to a department.
     *
     * Both seeded chains (company_loan, cash_advance) start at the borrower's
     * department_head; every later step is a company-level office.
     */
    private const DEPARTMENTAL_STEP_ROLE = 'department_head';

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
        $stepRoles = $this->plantWideStepRoles($user);

        if ($employeeId === null && $stepRoles === []) {
            return $query->whereRaw('1 = 0');
        }

        $isDepartmentHead = $user->role?->slug === self::DEPARTMENTAL_STEP_ROLE;
        $departmentId = $isDepartmentHead ? $this->departmentId($user) : null;

        return $query->where(function (Builder $scope) use ($employeeId, $isDepartmentHead, $departmentId, $stepRoles): void {
            $scope->where(function (Builder $own) use ($employeeId, $isDepartmentHead, $departmentId): void {
                if ($employeeId === null) {
                    $own->whereRaw('1 = 0');

                    return;
                }

                $own->where('employee_id', $employeeId);
                if ($isDepartmentHead && $departmentId !== null) {
                    $own->orWhereHas('employee', static fn (Builder $employee): Builder => $employee->where('department_id', $departmentId));
                }
            });

            // LN-01 — an approver on a plant-wide chain step has to be able to
            // find the loans waiting on them. approvalRecords is already
            // constrained to is_current and only exists once a loan is
            // submitted, so this never exposes a co-worker's unsubmitted row.
            // Same shape as PurchaseRequestAccessPolicy::visibleTo.
            if ($stepRoles !== []) {
                $scope->orWhereHas('approvalRecords', function ($records) use ($stepRoles): void {
                    $records->whereIn('role_slug', $stepRoles);
                });
            }
        });
    }

    public function canView(User $user, EmployeeLoan $loan): bool
    {
        return $this->canAccessEmployee($user, (int) $loan->employee_id)
            || $this->isChainParticipant($user, $loan);
    }

    public function canDecide(User $user, EmployeeLoan $loan): bool
    {
        return $this->canAccessEmployee($user, (int) $loan->employee_id)
            || $this->isChainParticipant($user, $loan);
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

        if ($user->role?->slug !== self::DEPARTMENTAL_STEP_ROLE) {
            return false;
        }

        $departmentId = $this->departmentId($user);
        if ($departmentId === null) {
            return false;
        }

        return (int) Employee::query()->whereKey($employeeId)->value('department_id') === $departmentId;
    }

    /**
     * You cannot approve what you cannot open: a plant-wide chain approver
     * reads the rows its own step appears on.
     */
    private function isChainParticipant(User $user, EmployeeLoan $loan): bool
    {
        $stepRoles = $this->plantWideStepRoles($user);
        if ($stepRoles === []) {
            return false;
        }

        return $this->currentRecords($loan)
            ->contains(static fn (ApprovalRecord $record): bool => in_array($record->role_slug, $stepRoles, true));
    }

    /**
     * Approval roles held directly or through an active delegation, minus the
     * departmental one: a department head's reach is already decided by its
     * department, and matching the department_head step here as well would
     * hand every head every other department's submitted loans.
     *
     * @return list<string>
     */
    private function plantWideStepRoles(User $user): array
    {
        $roles = [];
        if ($user->role?->slug !== null) {
            $roles[] = $user->role->slug;
        }

        $roles = array_values(array_unique([
            ...$roles,
            ...ApprovalDelegation::actsForRoles($user->id, now()),
        ]));

        return array_values(array_filter(
            $roles,
            static fn (string $slug): bool => $slug !== self::DEPARTMENTAL_STEP_ROLE,
        ));
    }

    /** @return Collection<int, ApprovalRecord> */
    private function currentRecords(EmployeeLoan $loan): Collection
    {
        // The relation is already constrained to is_current and ordered by
        // step_order, so a superseded attempt can never be read as live.
        return $loan->relationLoaded('approvalRecords')
            ? $loan->approvalRecords
            : $loan->approvalRecords()->get();
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
