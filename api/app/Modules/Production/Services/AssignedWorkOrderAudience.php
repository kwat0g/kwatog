<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderCrewMember;

/** Resolves active employee accounts authorized for self-service work-order reads. */
final class AssignedWorkOrderAudience
{
    /** @param list<int> $additionalEmployeeIds @return list<string> */
    public function userHashIdsFor(WorkOrder $workOrder, array $additionalEmployeeIds = []): array
    {
        $employeeIds = WorkOrderCrewMember::query()
            ->whereHas('crew', fn ($query) => $query
                ->where('work_order_id', $workOrder->getKey())
                ->whereNull('ended_at'))
            ->pluck('employee_id')
            ->merge($additionalEmployeeIds)
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($employeeIds->isEmpty()) {
            return [];
        }

        return User::query()
            ->without('role')
            ->where('is_active', true)
            ->whereIn('employee_id', $employeeIds)
            ->whereHas('employee', fn ($employee) => $employee
                ->where('status', 'active')
                ->whereHas('department', fn ($department) => $department
                    ->where('code', 'PROD')
                    ->where('is_active', true)))
            ->get(['id'])
            ->map(fn (User $user): string => (string) $user->hash_id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
