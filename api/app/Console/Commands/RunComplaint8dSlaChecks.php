<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\CRM\Services\Complaint8dEscalationService;
use Illuminate\Console\Command;

/**
 * T3.2.B — Tiered 8D SLA evaluation. Idempotent; safe at any cadence.
 * Scheduled every 15 minutes in routes/console.php.
 *
 * This command used to print only the per-tier advanced counts and always return
 * SUCCESS. A run in which every candidate threw therefore printed
 * `d3=0 d4=0 finalize=0` and exited 0 — byte-identical to an idle run — which is
 * the same blind spot that let the 8D SLA ledger sit dead for its entire life
 * (see CLAUDE.md). It now reports `failed` and `unstaffed` separately and exits
 * non-zero when any candidate could not be escalated.
 */
class RunComplaint8dSlaChecks extends Command
{
    protected $signature   = 'complaints:check-8d-slas';
    protected $description = 'Fire D3 / D4 / finalize SLA alerts for open customer complaints';

    public function handle(Complaint8dEscalationService $svc): int
    {
        $outcome = $svc->runWithOutcome();

        $this->info(sprintf(
            '8D SLA check completed: %d considered, %d advanced, %d skipped, %d unstaffed, %d failed.',
            $outcome['considered'],
            $outcome['advanced'],
            $outcome['skipped'],
            $outcome['unstaffed'],
            $outcome['failed'],
        ));

        if ($outcome['unstaffed'] > 0) {
            $this->warn(sprintf(
                '%d 8D SLA tier(s) reached nobody because the configured role has no active users. See complaint_8d_escalation_deliveries.last_error.',
                $outcome['unstaffed'],
            ));
        }

        if ($outcome['failed'] > 0) {
            $this->error(sprintf(
                '%d of %d complaint(s) failed to escalate. See complaint_8d_escalation_deliveries.last_error and the log.',
                $outcome['failed'],
                $outcome['considered'],
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
