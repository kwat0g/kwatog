<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Quality\Services\CalibrationService;
use Illuminate\Console\Command;

/**
 * OGAMI-016 — recompute due/overdue calibration statuses.
 * Idempotent; safe at any cadence. Scheduled daily in routes/console.php.
 */
class CheckCalibrationDue extends Command
{
    protected $signature   = 'calibration:check-due';
    protected $description = 'Recompute due/overdue status across the IATF calibration register';

    public function handle(CalibrationService $svc): int
    {
        $r = $svc->recomputeStatuses();
        $this->info(sprintf('Calibration check: %d due, %d overdue.', $r['due'], $r['overdue']));

        return self::SUCCESS;
    }
}
