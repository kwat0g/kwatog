<?php

declare(strict_types=1);

namespace App\Modules\Auth\Observers;

use App\Common\Services\UserAccessRefreshService;
use App\Modules\HR\Models\Employee;
use Illuminate\Support\Facades\DB;

/** Refresh a linked user's API scope after an employee department transfer. */
final class EmployeeAccessScopeRealtimeObserver
{
    public function updated(Employee $employee): void
    {
        if (! $employee->wasChanged('department_id')) {
            return;
        }

        $userId = DB::table('users')
            ->where('employee_id', $employee->getKey())
            ->value('id');
        if ($userId === null) {
            return;
        }

        app(UserAccessRefreshService::class)->stageForUsers([(int) $userId]);
    }
}
