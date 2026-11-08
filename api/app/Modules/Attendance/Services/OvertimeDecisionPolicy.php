<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\ApprovalDelegation;
use App\Modules\Attendance\Enums\OvertimeStatus;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Server-side authorization for an overtime decision.
 *
 * The list/detail queries are only a visibility aid. Approve, reject, and
 * bulk-approve must repeat the actor/department check in the locked write
 * transaction because a hash id is an identifier, not an authorization token.
 */
class OvertimeDecisionPolicy
{
    /** The pending rows this user can decide; a requester cannot approve their own request. */
    public function awaitingMyApprovalQuery(Builder $query, User $user): Builder
    {
        $query->where('status', OvertimeStatus::Pending->value);

        $canApproveDirectly = $user->hasPermission('attendance.ot.approve');
        $delegatorIds = $canApproveDirectly
            ? []
            : ApprovalDelegation::delegatorsForPermission($user, 'attendance.ot.approve')->modelKeys();
        if (! $canApproveDirectly && $delegatorIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $this->visibleTo($query, $user);

        if ($user->employee_id !== null) {
            $query->where('employee_id', '<>', $user->employee_id);
        }

        if ($delegatorIds !== []) {
            $query->whereDoesntHave('employee.user', fn (Builder $submitter) => $submitter->whereKey($delegatorIds));
        }

        return $query;
    }

    /** Reuse the overtime list's row scope for worklist surfaces. */
    public function visibleTo(Builder $query, User $user): Builder
    {
        if ($this->isAllRecordActor($user)) {
            return $query;
        }

        if (! ApprovalDelegation::canActForPermission($user, 'attendance.ot.approve')) {
            return $query->where('employee_id', $user->employee_id);
        }

        $departmentId = $user->employee_id
            ? Employee::query()->whereKey($user->employee_id)->value('department_id')
            : null;

        return $query->where(function (Builder $scope) use ($user, $departmentId): void {
            $scope->where('employee_id', $user->employee_id);
            if ($departmentId) {
                $scope->orWhereHas('employee', static fn (Builder $employee): Builder => $employee->where('department_id', $departmentId));
            }
        });
    }

    public function isAllRecordActor(User $actor): bool
    {
        return in_array($actor->role?->slug, ['system_admin', 'hr_officer'], true)
            || in_array('hr_officer', ApprovalDelegation::actsForRoles((int) $actor->id, now()), true);
    }

    /**
     * Server-owned action snapshot for a single overtime request. Mutation
     * services still repeat the authoritative state and scope checks under
     * the request row lock.
     *
     * @return array{permissions: array<string, bool>, available_actions: array<string, bool>}
     */
    public function actionContext(OvertimeRequest $overtime, ?User $actor): array
    {
        return $this->actionContextsForRows(new EloquentCollection([$overtime]), $actor)[$overtime->getKey()];
    }

    /**
     * Build the same server action snapshots for a page of list rows with a
     * bounded set of permission/scope queries rather than one query per row.
     *
     * @param EloquentCollection<int, OvertimeRequest> $requests
     * @return array<int, array{permissions: array<string, bool>, available_actions: array<string, bool>}>
     */
    public function actionContextsForRows(EloquentCollection $requests, ?User $actor): array
    {
        $requests->loadMissing('employee.user');
        $contexts = [];
        $empty = array_fill_keys(['approve', 'reject', 'cancel', 'restore'], false);

        if ($actor === null) {
            foreach ($requests as $request) {
                $contexts[$request->getKey()] = ['permissions' => $empty, 'available_actions' => $empty];
            }

            return $contexts;
        }

        $hasDirectApproval = $actor->hasPermission('attendance.ot.approve');
        $delegatorIds = $hasDirectApproval
            ? []
            : ApprovalDelegation::delegatorsForPermission($actor, 'attendance.ot.approve')->modelKeys();
        $isAllRecordActor = $this->isAllRecordActor($actor);
        $actorDepartmentId = $actor->employee_id
            ? Employee::query()->whereKey($actor->employee_id)->value('department_id')
            : null;

        foreach ($requests as $request) {
            $submitterUserId = $request->employee?->user?->id;
            $isSelf = $submitterUserId !== null && (int) $submitterUserId === (int) $actor->id;
            $hasApprovalAuthority = $hasDirectApproval
                || ($delegatorIds !== [] && ! in_array((int) $submitterUserId, $delegatorIds, true));
            $sameDepartment = $actorDepartmentId !== null
                && $request->employee?->department_id !== null
                && (int) $actorDepartmentId === (int) $request->employee->department_id;
            $canDecide = ! $isSelf
                && $hasApprovalAuthority
                && ($isAllRecordActor || $sameDepartment);

            // Match OvertimeController::cancel(): direct approver permission
            // is required for department-wide cancellation; delegation alone
            // grants approval but does not broaden cancellation authority.
            $isOwner = (int) $actor->employee_id === (int) $request->employee_id;
            $canCancel = $isAllRecordActor || $isOwner || ($hasDirectApproval && $sameDepartment);
            $canRestore = $isOwner
                && (int) $request->cancelled_by === (int) $actor->id
                && $request->cancelled_at !== null;
            $isPending = $request->status === OvertimeStatus::Pending;

            $contexts[$request->getKey()] = [
                'permissions' => [
                    'approve' => $canDecide,
                    'reject' => $canDecide,
                    'cancel' => $canCancel,
                    'restore' => $canRestore,
                ],
                'available_actions' => [
                    'approve' => $isPending && $canDecide,
                    'reject' => $isPending && $canDecide,
                    'cancel' => $isPending && $canCancel,
                    'restore' => $request->status === OvertimeStatus::Rejected && $canRestore,
                ],
            ];
        }

        return $contexts;
    }

    /**
     * HR and system administrators may decide any record. Department heads
     * may decide only records belonging to their own department.
     */
    public function assertCanDecide(OvertimeRequest $overtime, User $actor): void
    {
        if ($this->isAllRecordActor($actor)) {
            return;
        }

        $approverDepartmentId = $actor->employee_id
            ? Employee::query()->whereKey($actor->employee_id)->value('department_id')
            : null;
        $recordDepartmentId = $overtime->employee?->department_id
            ?? Employee::query()->whereKey($overtime->employee_id)->value('department_id');

        if ($approverDepartmentId === null || $recordDepartmentId === null
            || (int) $approverDepartmentId !== (int) $recordDepartmentId) {
            throw new BusinessRuleException('You may only decide overtime requests for your department.');
        }
    }

    public function canApproveWithinScope(OvertimeRequest $overtime, User $actor): bool
    {
        try {
            $this->assertCanDecide($overtime, $actor);

            return true;
        } catch (BusinessRuleException) {
            return false;
        }
    }

    /**
     * The requester cannot be the person who decides the request.
     */
    public function assertNotSelfDecision(OvertimeRequest $overtime, User $actor): void
    {
        $submitterId = $overtime->employee?->user?->id;

        if ($submitterId !== null && (int) $submitterId === (int) $actor->id) {
            throw new BusinessRuleException('You cannot approve your own overtime request.');
        }
        if (! ApprovalDelegation::canActForPermission(
            $actor,
            'attendance.ot.approve',
            $submitterId === null ? [] : [(int) $submitterId],
        )) {
            throw new BusinessRuleException('You do not hold approval authority for this overtime request.');
        }
    }
}
