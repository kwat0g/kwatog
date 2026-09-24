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
        $outcome = $svc->runWithOutcome();

        $this->info(sprintf(
            'Approval escalation completed: %d reminders, %d escalations, %d auto-resolved, %d unstaffed, %d failed.',
            $outcome['reminders'],
            $outcome['escalations'],
            $outcome['auto_resolved'],
            $outcome['unstaffed'],
            $outcome['failed'],
        ));

        if ($outcome['unstaffed'] > 0) {
            $this->warn(sprintf(
                '%d approval candidate(s) had no active approver or superior. They remain eligible for the next sweep.',
                $outcome['unstaffed'],
            ));
        }

        if ($outcome['failed'] > 0) {
            $this->error(sprintf(
                '%d approval candidate(s) failed during escalation processing. See the log for details.',
                $outcome['failed'],
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
