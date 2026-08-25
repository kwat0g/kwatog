<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\AlertEngineService;
use Illuminate\Console\Command;

class PruneResolvedAlerts extends Command
{
    protected $signature = 'alerts:prune {--months=12 : Delete resolved alerts older than N months}';

    protected $description = 'Delete resolved alert history older than the retention window';

    public function handle(AlertEngineService $engine): int
    {
        $months = (int) $this->option('months');
        if ($months < 1) {
            $this->error('The --months option must be at least 1.');

            return self::FAILURE;
        }

        $deleted = $engine->pruneResolved($months);
        $this->info("Pruned {$deleted} resolved alerts older than {$months} month(s).");

        return self::SUCCESS;
    }
}
