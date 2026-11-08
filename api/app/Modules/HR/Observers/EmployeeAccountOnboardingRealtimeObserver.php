<?php

declare(strict_types=1);

namespace App\Modules\HR\Observers;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\EmployeeRealtimeService;
use App\Modules\HR\Services\EmployeeOnboardingRealtimeService;

/** Refreshes employee account projections and onboarding when linked Users change. */
final class EmployeeAccountOnboardingRealtimeObserver
{
    /** @var list<string> */
    private const EMPLOYEE_ACCOUNT_FIELDS = [
        'employee_id',
        'name',
        'email',
        'role_id',
        'is_active',
        'must_change_password',
        'last_activity',
        'locked_until',
        'deleted_at',
    ];

    public function created(User $user): void
    {
        $employeeId = (int) $user->employee_id;
        $version = $user->updated_at?->format('Y-m-d\\TH:i:s.uP');
        $this->stageOnboarding($employeeId, 'updated', $version);
        $this->stageEmployee($employeeId);
    }

    public function updated(User $user): void
    {
        if (! $user->wasChanged(self::EMPLOYEE_ACCOUNT_FIELDS)) {
            return;
        }

        $linkChanged = $user->wasChanged('employee_id');
        $employeeIds = $linkChanged
            ? array_unique(array_filter([
                (int) $user->getRawOriginal('employee_id'),
                (int) $user->employee_id,
            ]))
            : [(int) $user->employee_id];
        $version = $user->updated_at?->format('Y-m-d\\TH:i:s.uP');

        foreach ($employeeIds as $employeeId) {
            $this->stageEmployee($employeeId);
            if ($linkChanged) {
                $this->stageOnboarding($employeeId, 'updated', $version);
            }
        }
    }

    public function deleted(User $user): void
    {
        $this->stageEmployee((int) $user->employee_id);
    }

    private function stageEmployee(int $employeeId): void
    {
        if ($employeeId <= 0) {
            return;
        }

        $employee = Employee::query()->find($employeeId);
        if ($employee !== null) {
            app(EmployeeRealtimeService::class)->stage(
                $employee,
                'updated',
                refreshAdminUserDirectory: false,
            );
        }
    }

    private function stageOnboarding(int $employeeId, string $action, ?string $version): void
    {
        app(EmployeeOnboardingRealtimeService::class)->stageForEmployee($employeeId, $action, $version);
    }
}
