<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Quality\Services\NcrEscalationService;
use Illuminate\Console\Command;

/**
 * T3.1.C — Run NCR SLA escalation. Idempotent; safe at any cadence.
 * Scheduled every 15 minutes in routes/console.php.
 *
 * This command used to print only the advanced count and always return
 * SUCCESS, which made a run where every candidate threw byte-identical to an
 * idle run — the same blind spot that let the 8D SLA ledger sit dead for its
 * entire life (see CLAUDE.md). It now reports each outcome separately and
 * fails when any candidate could not be escalated.
 */
class RunNcrEscalations extends Command
{
    protected $signature   = 'ncr:escalate';
    protected $description = 'Advance NCR escalation tiers for open NCRs without a corrective action';

    public function handle(NcrEscalationService $svc): int
    {
        $outcome = $svc->runWithOutcome();

        $this->info(sprintf(
            'NCR escalation completed: %d considered, %d advanced, %d skipped, %d unstaffed, %d failed.',
            $outcome['considered'],
            $outcome['advanced'],
            $outcome['skipped'],
            $outcome['unstaffed'],
            $outcome['failed'],
        ));

        if ($outcome['unstaffed'] > 0) {
            $this->warn(sprintf(
                '%d escalation tier(s) reached nobody because the configured role has no active users. See ncr_escalation_deliveries.last_error.',
                $outcome['unstaffed'],
            ));
        }

        if ($outcome['failed'] > 0) {
            // A zero-count summary and a green exit code must never be able to
            // describe a run in which nothing survived.
            $this->error(sprintf(
                '%d of %d NCR escalation(s) failed to deliver. See ncr_escalation_deliveries.last_error and the log.',
                $outcome['failed'],
                $outcome['considered'],
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
