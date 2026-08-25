<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;

/**
 * Server-side authorization for an overtime decision.
 *
 * The list/detail queries are only a visibility aid. Approve, reject, and
 * bulk-approve must repeat the actor/department check in the locked write
 * transaction because a hash id is an identifier, not an authorization token.
 */
class OvertimeDecisionPolicy
{
    public function isAllRecordActor(User $actor): bool
    {
        return in_array($actor->role?->slug, ['system_admin', 'hr_officer'], true);
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

    /**
     * The requester cannot be the person who decides the request.
     */
    public function assertNotSelfDecision(OvertimeRequest $overtime, User $actor): void
    {
        $submitterId = $overtime->employee?->user?->id;

        if ($submitterId !== null && (int) $submitterId === (int) $actor->id) {
            throw new BusinessRuleException('You cannot approve your own overtime request.');
        }
    }
}
