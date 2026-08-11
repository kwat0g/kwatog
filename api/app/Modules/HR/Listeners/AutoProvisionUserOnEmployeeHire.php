<?php

declare(strict_types=1);

namespace App\Modules\HR\Listeners;

use App\Common\Services\ChainListenerRunService;
use App\Common\Services\SettingsService;
use App\Modules\HR\Events\EmployeeCreated;
use App\Modules\HR\Exceptions\AccountAlreadyProvisionedException;
use App\Modules\HR\Exceptions\EmployeeNoLongerExistsException;
use App\Modules\HR\Services\UserProvisioningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class AutoProvisionUserOnEmployeeHire implements ShouldQueue
{
    public function __construct(
        private readonly UserProvisioningService $provisioning,
        private readonly SettingsService $settings,
    ) {}

    public function handle(EmployeeCreated $event): void
    {
        if (! $this->settings->requiredBool('hr.auto_provision_user.enabled')) {
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'feature_disabled');
            return;
        }

        try {
            $user = $this->provisioning->provisionForEmployee($event->employee);
            app(ChainListenerRunService::class)->recordOutcome(
                'completed',
                'employee_system_account_provisioned',
                "Provisioned system account {$user->email} for the hired employee.",
            );
        } catch (AccountAlreadyProvisionedException $e) {
            // Account already exists — re-fire of the event is a no-op.
            Log::info('AutoProvisionUserOnEmployeeHire skipped: '.$e->getMessage(), [
                'employee_id' => $event->employee->id,
            ]);
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'employee_account_already_present');
        } catch (EmployeeNoLongerExistsException $e) {
            // A queued hire event can outlive a soft-deleted employee. That is
            // a terminal stale-event outcome, not an account-provisioning
            // success and not a retryable infrastructure failure.
            Log::info('AutoProvisionUserOnEmployeeHire skipped stale employee event', [
                'employee_id' => $event->employee->id,
            ]);
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'employee_no_longer_exists');
        }
    }
}
