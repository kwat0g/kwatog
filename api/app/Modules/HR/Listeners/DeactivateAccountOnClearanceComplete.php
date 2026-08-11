<?php

declare(strict_types=1);

namespace App\Modules\HR\Listeners;

use App\Common\Services\ChainListenerRunService;
use App\Modules\HR\Events\ClearanceFullySigned;
use App\Modules\HR\Enums\ClearanceStatus;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Services\UserProvisioningService;
use Illuminate\Contracts\Queue\ShouldQueue;

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
 * Stateful failures are rethrown for queue retry; the provisioning service
 * keeps the operation idempotent.
 */
class DeactivateAccountOnClearanceComplete implements ShouldQueue
{
    public function __construct(private readonly UserProvisioningService $provisioning) {}

    public function handle(ClearanceFullySigned $event): void
    {
        // The event payload identifies the aggregate; it is not the authority
        // for the current state. A delayed/replayed completion event must not
        // deactivate an account after the clearance was cancelled or otherwise
        // moved out of the signed/finalized states.
        $clearance = Clearance::query()
            ->with('employee')
            ->find($event->clearance->id);

        if (! $clearance || ! in_array($clearance->status, [
            ClearanceStatus::Completed,
            ClearanceStatus::Finalized,
        ], true)) {
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'stale_or_not_completed_clearance');
            return;
        }

        if (! $clearance->employee) {
            app(ChainListenerRunService::class)->recordOutcome('skipped', 'separating_employee_missing');
            return;
        }
        $this->provisioning->deactivateForEmployee($clearance->employee);

        app(ChainListenerRunService::class)->recordOutcome(
            'completed',
            'employee_account_deactivation_reconciled',
            'Employee account access was deactivated or confirmed absent.',
        );
    }
}
