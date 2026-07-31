<?php

declare(strict_types=1);

namespace App\Modules\Leave\Controllers;

use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\EmployeeLeaveBalance;
use App\Modules\Leave\Resources\EmployeeLeaveBalanceResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeaveBalanceController
{
    public function me(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $year = (int) ($request->query('year') ?? now()->year);
        $rows = EmployeeLeaveBalance::query()
            ->with('leaveType')
            ->where('employee_id', $user->employee_id)
            ->where('year', $year)
            ->get();

        return EmployeeLeaveBalanceResource::collection($rows);
    }

    public function forEmployee(Employee $employee, Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $canView = $user?->role?->slug === 'system_admin'
            || $user?->hasPermission('leave.approve_hr')
            || (int) $user?->employee_id === (int) $employee->id;

        if (! $canView && $user?->hasPermission('leave.approve_dept') && $user->employee_id) {
            $ownDepartment = Employee::query()->whereKey($user->employee_id)->value('department_id');
            $canView = $ownDepartment && (int) $ownDepartment === (int) $employee->department_id;
        }

        abort_unless($canView, 403, 'You do not have permission to view this leave balance.');

        $year = (int) ($request->query('year') ?? now()->year);
        $rows = EmployeeLeaveBalance::query()
            ->with('leaveType')
            ->where('employee_id', $employee->id)
            ->where('year', $year)
            ->get();

        return EmployeeLeaveBalanceResource::collection($rows);
    }
}
