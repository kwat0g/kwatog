<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\SchedulerExecutionLedger;
use Illuminate\Console\Command;

/**
 * Fail-closed scheduler health probe for a supervisor, container healthcheck,
 * or external monitor. It is intentionally separate from schedule:run: a
 * dead scheduler cannot report its own health, so this command can be invoked
 * by an independent process as well.
 */
class SchedulerHealth extends Command
{
    protected $signature = 'scheduler:health {--stale-minutes=15 : Maximum age of a healthy tick}';

    protected $description = 'Verify durable scheduler heartbeat and task execution health';

    public function handle(SchedulerExecutionLedger $ledger): int
    {
        $staleMinutes = (int) $this->option('stale-minutes');
        if ($staleMinutes < 1 || $staleMinutes > 1440) {
            $this->error('--stale-minutes must be between 1 and 1440.');

            return self::FAILURE;
        }

        $report = $ledger->health($staleMinutes);
        $latestTick = $report['latest_tick'];
        if ($latestTick) {
            $this->line(sprintf(
                'Latest scheduler tick: %s (%s).',
                $latestTick->started_at?->toDateTimeString() ?? 'unknown',
                $latestTick->status,
            ));
        }

        foreach ($report['issues'] as $issue) {
            $this->error($issue);
        }

        if ($report['healthy']) {
            $this->info('Scheduler health is OK.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }
}
