<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\ApprovalEscalationService;
use Illuminate\Console\Command;

/**
 * Task A7 — Run approval reminders + escalations.
 * Scheduled every 6 hours in routes/console.php.
 */
class RunApprovalEscalations extends Command
{
    protected $signature   = 'approvals:run-escalations';
    protected $description = 'Send reminder/escalation notifications for stale approvals (Task A7)';

    public function handle(ApprovalEscalationService $svc): int
    {
        $reminders   = $svc->runReminders();
        $escalations = $svc->runEscalations();
        $autoResolved = $svc->runAutoResolve();

        $this->info(sprintf(
            'Approval escalation completed: %d reminders, %d escalations, %d auto-resolved.',
            $reminders, $escalations, $autoResolved,
        ));
        return self::SUCCESS;
    }
}
