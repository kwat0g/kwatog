<?php

declare(strict_types=1);

namespace App\Modules\HR\Observers;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\EmployeeRealtimeService;

/** Captures employee writes from services, scheduled jobs, and imports. */
final class EmployeeRealtimeObserver
{
    public function created(Employee $employee): void
    {
        app(EmployeeRealtimeService::class)->stage($employee, 'created');
    }

    public function updated(Employee $employee): void
    {
        $action = match (true) {
            $employee->wasChanged('deleted_at') && $employee->deleted_at === null => 'restored',
            $employee->wasChanged('status') => 'status_changed',
            default => 'updated',
        };

        app(EmployeeRealtimeService::class)->stage($employee, $action);
    }

    public function deleted(Employee $employee): void
    {
        app(EmployeeRealtimeService::class)->stage($employee, 'deleted');
    }
}
