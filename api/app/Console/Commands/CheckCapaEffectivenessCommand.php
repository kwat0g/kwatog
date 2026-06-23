<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Quality\Services\EffectivenessService;
use Illuminate\Console\Command;

/**
 * CAPA effectiveness loop (IATF 16949 §10.2.1) — notify owners of due/overdue
 * verification checks. Idempotent; safe at any cadence. Scheduled daily.
 */
class CheckCapaEffectivenessCommand extends Command
{
    protected $signature   = 'ncr:check-effectiveness';
    protected $description = 'Notify owners of due/overdue CAPA effectiveness verification checks';

    public function handle(EffectivenessService $svc): int
    {
        $count = $svc->notifyOverdueChecks();
        $this->info("CAPA effectiveness: {$count} action(s) due for verification.");

        return self::SUCCESS;
    }
}
