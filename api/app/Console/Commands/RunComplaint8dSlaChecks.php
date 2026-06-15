<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\CRM\Services\Complaint8dEscalationService;
use Illuminate\Console\Command;

/**
 * T3.2.B — Tiered 8D SLA evaluation. Idempotent; safe at any cadence.
 * Scheduled every 15 minutes in routes/console.php.
 */
class RunComplaint8dSlaChecks extends Command
{
    protected $signature   = 'complaints:check-8d-slas';
    protected $description = 'Fire D3 / D4 / finalize SLA alerts for open customer complaints';

    public function handle(Complaint8dEscalationService $svc): int
    {
        $counts = $svc->run();
        $this->info(sprintf(
            '8D SLA check complete: d3=%d d4=%d finalize=%d',
            $counts['d3'], $counts['d4'], $counts['finalize'],
        ));
        return self::SUCCESS;
    }
}
