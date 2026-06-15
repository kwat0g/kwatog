<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\HR\Services\TrainingExpiryService;
use Illuminate\Console\Command;

/**
 * T3.4.C — Tiered training-expiry alerts. Idempotent; safe at any cadence.
 * Scheduled daily at 06:30 in routes/console.php.
 */
class CheckTrainingExpiries extends Command
{
    protected $signature   = 'training:check-expiries';
    protected $description = 'Send tiered alerts for expiring/expired training records (30/14/7/expired)';

    public function handle(TrainingExpiryService $svc): int
    {
        $r = $svc->check();
        $this->info(sprintf(
            'Training expiry check: %d evaluated, %d alerts, %d expired.',
            $r['evaluated'],
            $r['alerts_sent'],
            $r['expired_marked'],
        ));

        return self::SUCCESS;
    }
}
