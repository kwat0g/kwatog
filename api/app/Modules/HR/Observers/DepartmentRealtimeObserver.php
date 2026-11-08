<?php

declare(strict_types=1);

namespace App\Modules\HR\Observers;

use App\Modules\HR\Models\Department;
use App\Modules\HR\Services\DepartmentRealtimeService;
use App\Modules\Production\Services\AssignedWorkOrderRefreshService;
use Illuminate\Support\Facades\DB;

/** Captures department writes from controllers and other Eloquent code paths. */
final class DepartmentRealtimeObserver
{
    public function created(Department $department): void
    {
        app(DepartmentRealtimeService::class)->stage($department, 'created');
    }

    public function updated(Department $department): void
    {
        $action = $department->wasChanged('deleted_at') && $department->deleted_at === null
            ? 'restored'
            : 'updated';

        app(DepartmentRealtimeService::class)->stage($department, $action);

        if ($department->wasChanged(['code', 'is_active', 'deleted_at'])) {
            $this->refreshCrewReadiness($department);
        }
    }

    public function deleted(Department $department): void
    {
        app(DepartmentRealtimeService::class)->stage($department, 'deleted');
        $this->refreshCrewReadiness($department);
    }

    public function restored(Department $department): void
    {
        app(DepartmentRealtimeService::class)->stage($department, 'restored');
        $this->refreshCrewReadiness($department);
    }

    private function refreshCrewReadiness(Department $department): void
    {
        $employeeIds = DB::table('employees')
            ->where('department_id', $department->getKey())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        app(AssignedWorkOrderRefreshService::class)->stageForEmployees($employeeIds);
    }
}
