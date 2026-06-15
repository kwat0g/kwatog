<?php

declare(strict_types=1);

namespace App\Modules\HR\Listeners;

use App\Common\Services\SettingsService;
use App\Modules\HR\Events\EmployeeCreated;
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
        if (! (bool) $this->settings->get('hr.auto_provision_user.enabled', true)) {
            return;
        }

        try {
            $this->provisioning->provisionForEmployee($event->employee);
        } catch (\DomainException $e) {
            // Account already exists — re-fire of the event is a no-op.
            Log::info('AutoProvisionUserOnEmployeeHire skipped: '.$e->getMessage(), [
                'employee_id' => $event->employee->id,
            ]);
        } catch (\Throwable $e) {
            // Provisioning must never fail the hire flow.
            Log::warning('AutoProvisionUserOnEmployeeHire failed', [
                'employee_id' => $event->employee->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
