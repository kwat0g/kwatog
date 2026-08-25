<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Policies;

use App\Common\Models\ApprovalDelegation;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Illuminate\Database\Eloquent\Builder;

/**
 * Server-side row and action scope for purchase requests.
 *
 * Permissions decide whether a route is available. This policy decides which
 * PR rows that permission may touch; the two checks must remain separate.
 */
final class PurchaseRequestAccessPolicy
{
    /**
     * @param Builder<PurchaseRequest> $query
     * @return Builder<PurchaseRequest>
     */
    public function visibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->isGlobal($user)) {
            return $query;
        }

        $departmentId = $this->departmentId($user);
        if ($user->role?->slug === 'department_head') {
            return $query->where(function (Builder $scope) use ($user, $departmentId): void {
                $scope->where('requested_by', $user->id);
                if ($departmentId !== null) {
                    $scope->orWhere('department_id', $departmentId);
                }
            });
        }

        return $query->where('requested_by', $user->id);
    }

    public function canView(User $user, PurchaseRequest $pr): bool
    {
        if ($this->isGlobal($user)) {
            return true;
        }

        if ((int) $pr->requested_by === (int) $user->id) {
            return true;
        }

        return $user->role?->slug === 'department_head'
            && $this->departmentId($user) !== null
            && (int) $pr->department_id === $this->departmentId($user);
    }

    public function canManageDraft(User $user, PurchaseRequest $pr): bool
    {
        return $pr->status === PurchaseRequestStatus::Draft
            && $this->canView($user, $pr)
            && ($this->isGlobal($user) || (int) $pr->requested_by === (int) $user->id);
    }

    public function canCancel(User $user, PurchaseRequest $pr): bool
    {
        return in_array($pr->status, [PurchaseRequestStatus::Draft, PurchaseRequestStatus::Pending], true)
            && $this->canView($user, $pr)
            && ($this->isGlobal($user) || (int) $pr->requested_by === (int) $user->id);
    }

    public function canAssignDepartment(User $user, PurchaseRequest $pr, ?int $departmentId): bool
    {
        if (! $this->canManageDraft($user, $pr)) {
            return false;
        }

        return $this->isGlobal($user)
            || ($departmentId !== null && $departmentId === $this->departmentId($user));
    }

    public function canApprove(User $user, PurchaseRequest $pr): bool
    {
        if (! $user->hasPermission('purchasing.pr.approve')
            || $pr->status !== PurchaseRequestStatus::Pending
            || ! $this->canView($user, $pr)
            || (int) $pr->requested_by === (int) $user->id) {
            return false;
        }

        $next = $this->nextPendingRecord($pr);

        return $next !== null
            && in_array($next->role_slug, $this->approvalRoleSlugs($user), true);
    }

    public function canReject(User $user, PurchaseRequest $pr): bool
    {
        return $this->canApprove($user, $pr);
    }

    public function canAcknowledgeBudget(User $user, PurchaseRequest $pr): bool
    {
        if (! $user->hasPermission('budgeting.approve')) {
            return false;
        }

        // Finance acknowledges the budget gate across departments but is not
        // granted general purchasing row visibility by that permission.
        return $this->canView($user, $pr)
            || in_array($user->role?->slug, ['system_admin', 'finance_officer'], true);
    }

    public function canConvert(User $user, PurchaseRequest $pr): bool
    {
        return $user->hasPermission('purchasing.po.create') && $this->canView($user, $pr);
    }

    /**
     * Approval roles held directly or through an active delegation.
     *
     * @return list<string>
     */
    public function approvalRoleSlugs(User $user): array
    {
        $roles = [];
        if ($user->role?->slug !== null) {
            $roles[] = $user->role->slug;
        }

        return array_values(array_unique([
            ...$roles,
            ...ApprovalDelegation::actsForRoles($user->id, now()),
        ]));
    }

    /**
     * @return array<string, bool>
     */
    public function actionsFor(User $user, PurchaseRequest $pr): array
    {
        $canManageDraft = $this->canManageDraft($user, $pr);

        return [
            'can_view'               => $this->canView($user, $pr),
            'can_update'             => $canManageDraft,
            'can_delete'             => $canManageDraft,
            'can_submit'             => $canManageDraft,
            'can_cancel'             => $this->canCancel($user, $pr),
            'can_approve'            => $this->canApprove($user, $pr),
            'can_reject'             => $this->canReject($user, $pr),
            'can_acknowledge_budget' => $this->canAcknowledgeBudget($user, $pr),
            'can_convert'            => $this->canConvert($user, $pr),
            'can_print'              => $this->canView($user, $pr),
        ];
    }

    private function nextPendingRecord(PurchaseRequest $pr): ?object
    {
        $records = $pr->relationLoaded('approvalRecords')
            ? $pr->approvalRecords
            : $pr->approvalRecords()->get();

        return $records
            ->where('action', 'pending')
            ->sortBy('step_order')
            ->first();
    }

    private function isGlobal(User $user): bool
    {
        // These are the seeded company-wide PR operators. An approve
        // permission alone does not grant company-wide row visibility.
        return in_array($user->role?->slug, ['system_admin', 'purchasing_officer'], true);
    }

    private function departmentId(User $user): ?int
    {
        if ($user->employee_id === null) {
            return null;
        }

        $departmentId = $user->relationLoaded('employee')
            ? $user->employee?->department_id
            : Employee::query()->whereKey($user->employee_id)->value('department_id');

        return $departmentId !== null ? (int) $departmentId : null;
    }
}
