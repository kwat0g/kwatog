<?php

declare(strict_types=1);

namespace App\Modules\HR\Listeners;

use App\Modules\HR\Events\ClearanceFullySigned;
use App\Modules\HR\Services\UserProvisioningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Series C — Task C3. When the clearance for a separating employee is
 * fully signed off, deactivate their system account and revoke active
 * sessions. The actual final-pay computation remains a deliberate
 * Finance step (SeparationService::finalize); this listener handles
 * only the IT/access side.
 *
 * Idempotent: UserProvisioningService::deactivateForEmployee is itself
 * idempotent (sets is_active=false, deletes sessions — re-running has
 * no further effect).
 *
 * Best-effort.
 */
class DeactivateAccountOnClearanceComplete implements ShouldQueue
{
    public function __construct(private readonly UserProvisioningService $provisioning) {}

    public function handle(ClearanceFullySigned $event): void
    {
        try {
            $clearance = $event->clearance->loadMissing('employee');
            if (! $clearance->employee) return;
            $this->provisioning->deactivateForEmployee($clearance->employee);
        } catch (\Throwable $e) {
            Log::warning('DeactivateAccountOnClearanceComplete failed', [
                'clearance_id' => $event->clearance->id ?? null,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}
