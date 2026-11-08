<?php

declare(strict_types=1);

namespace App\Modules\HR\Observers;

use App\Modules\HR\Models\EmployeeOnboarding;
use App\Modules\HR\Services\EmployeeOnboardingRealtimeService;

final class EmployeeOnboardingRealtimeObserver
{
    /** @var list<string> */
    private const STATUS_FIELDS = [
        'profile_completed_at',
        'shift_assigned_at',
        'leave_balances_initialized_at',
        'account_provisioned_at',
        'dept_team_notified_at',
        'gov_ids_recorded_at',
        'banking_recorded_at',
        'completed_at',
    ];

    public function created(EmployeeOnboarding $onboarding): void
    {
        $this->stage($onboarding, 'created');
    }

    public function updated(EmployeeOnboarding $onboarding): void
    {
        if ($onboarding->wasChanged(self::STATUS_FIELDS)) {
            $this->stage($onboarding, 'updated');
        }
    }

    private function stage(EmployeeOnboarding $onboarding, string $action): void
    {
        app(EmployeeOnboardingRealtimeService::class)->stage($onboarding, $action);
    }
}
