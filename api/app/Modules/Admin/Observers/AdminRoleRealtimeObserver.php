<?php

declare(strict_types=1);

namespace App\Modules\Admin\Observers;

use App\Modules\Admin\Services\AdminRoleRealtimeService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Production\Services\AssignedWorkOrderRefreshService;

/** Refreshes Admin Role readers when a role API representation changes. */
final class AdminRoleRealtimeObserver
{
    private const DIRECTORY_FIELDS = [
        'name', 'slug', 'description', 'is_system', 'permissions_version', 'deleted_at',
    ];

    public function created(Role $role): void
    {
        app(AdminRoleRealtimeService::class)->stage($role, 'created');
    }

    public function updated(Role $role): void
    {
        if (! $role->wasChanged(self::DIRECTORY_FIELDS)) {
            return;
        }

        $action = $role->wasChanged(['deleted_at'])
            ? ($role->trashed() ? 'deleted' : 'restored')
            : 'updated';

        app(AdminRoleRealtimeService::class)->stage($role, $action);

        if ($role->wasChanged(['slug', 'deleted_at'])) {
            $this->refreshCrewReadiness($role);
        }
    }

    public function deleted(Role $role): void
    {
        app(AdminRoleRealtimeService::class)->stage($role, 'deleted');
        $this->refreshCrewReadiness($role);
    }

    public function restored(Role $role): void
    {
        app(AdminRoleRealtimeService::class)->stage($role, 'restored');
        $this->refreshCrewReadiness($role);
    }

    private function refreshCrewReadiness(Role $role): void
    {
        $employeeIds = User::query()
            ->where('role_id', $role->getKey())
            ->whereNotNull('employee_id')
            ->pluck('employee_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        app(AssignedWorkOrderRefreshService::class)->stageForEmployees($employeeIds);
    }
}
