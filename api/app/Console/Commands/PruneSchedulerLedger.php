<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\SchedulerExecutionLedger;
use Illuminate\Console\Command;

class PruneSchedulerLedger extends Command
{
    protected $signature = 'scheduler:prune-ledger {--days=90 : Retain terminal scheduler evidence for this many days}';

    protected $description = 'Prune old terminal scheduler execution evidence while retaining stuck runs';

    public function handle(SchedulerExecutionLedger $ledger): int
    {
        $days = (int) $this->option('days');
        if ($days < 7 || $days > 3650) {
            $this->error('--days must be between 7 and 3650.');

            return self::FAILURE;
        }

        $deleted = $ledger->prune($days);
        $this->info(sprintf(
            'Pruned scheduler evidence: task_runs=%d tick_runs=%d (retention=%d days).',
            $deleted['task_runs'],
            $deleted['tick_runs'],
            $days,
        ));

        return self::SUCCESS;
    }
}
