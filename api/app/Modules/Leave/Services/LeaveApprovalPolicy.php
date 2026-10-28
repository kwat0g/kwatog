<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Common\Services\ApprovalService;
use App\Common\Support\DepartmentScope;
use App\Modules\Auth\Models\User;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Leave\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Builder;

class LeaveApprovalPolicy
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function canApprove(LeaveRequest $request, User $user): bool
    {
        return $this->awaitingMyApprovalQuery(LeaveRequest::query(), $user)
            ->whereKey($request->getKey())
            ->exists();
    }

    /** The leave rows this user can approve at the current workflow step. */
    public function awaitingMyApprovalQuery(Builder $query, User $user): Builder
    {
        $stages = [];
        if ($user->hasPermission('leave.approve_dept') && $this->approvals->canUserActFor($user, 'department_head')) {
            $stages[LeaveRequestStatus::PendingDept->value] = ['department_head', 1];
        }
        if ($user->hasPermission('leave.approve_hr') && $this->approvals->canUserActFor($user, 'hr_officer')) {
            $stages[LeaveRequestStatus::PendingHr->value] = ['hr_officer', 2];
        }

        if ($stages === []) {
            return $query->whereRaw('1 = 0');
        }

        $query->where(function (Builder $queue) use ($stages): void {
            foreach ($stages as $status => [$role, $step]) {
                $queue->orWhere(function (Builder $stage) use ($status, $role, $step): void {
                    $stage->where('status', $status)
                        ->whereHas('approvalRecords', fn (Builder $record) => $record
                            ->where('step_order', $step)
                            ->where('role_slug', $role)
                            ->where('action', 'pending'));
                });
            }
        });
        $query->whereDoesntHave('approvalRecords', fn (Builder $record) => $record
            ->where('approver_id', $user->id)
            ->whereIn('action', ['approved', 'rejected']));
        DepartmentScope::apply(
            $query,
            $user,
            viewAllPermission: 'leave.approve_hr',
            departmentPermission: 'leave.approve_dept',
            deptColumn: 'department_id',
            selfColumn: 'employee_id',
            selfId: $user->employee_id ? (int) $user->employee_id : null,
            deptRelation: 'employee',
        );

        if ($user->employee_id !== null) {
            $query->where('employee_id', '<>', $user->employee_id);
        }

        return $query;
    }
}
