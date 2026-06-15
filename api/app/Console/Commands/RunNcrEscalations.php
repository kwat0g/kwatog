<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Quality\Services\NcrEscalationService;
use Illuminate\Console\Command;

/**
 * T3.1.C — Run NCR SLA escalation. Idempotent; safe at any cadence.
 * Scheduled every 15 minutes in routes/console.php.
 */
class RunNcrEscalations extends Command
{
    protected $signature   = 'ncr:escalate';
    protected $description = 'Advance NCR escalation tiers for open NCRs without a corrective action';

    public function handle(NcrEscalationService $svc): int
    {
        $advanced = $svc->run();
        $this->info(sprintf('NCR escalation completed: %d advanced.', $advanced));
        return self::SUCCESS;
    }
}
