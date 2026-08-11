<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\SchedulerExecutionLedger;
use App\Common\Services\ScheduleTickFailureTracker;
use Illuminate\Console\Command;
use Throwable;

/**
 * Run one scheduler tick and preserve a non-zero result when any due task
 * failed. Laravel's native schedule:run catches task exceptions by design;
 * this wrapper lets the production supervisor restart a wedged scheduler
 * without preventing the remaining due tasks from being attempted.
 */
class RunScheduleTick extends Command
{
    protected $signature = 'schedule:run-fail-fast';

    protected $description = 'Run one scheduler tick and fail when a scheduled task fails';

    public function handle(ScheduleTickFailureTracker $failures, SchedulerExecutionLedger $ledger): int
    {
        $failures->reset();
        $tickId = $ledger->beginTick();
        $exitCode = 0;
        $error = null;

        try {
            $exitCode = $this->call('schedule:run', ['--no-interaction' => true]);
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
            throw $exception;
        } finally {
            $ledger->finishTick($tickId, $failures->count(), $error, $exitCode);
        }

        if ($failures->count() > 0) {
            $this->error(sprintf(
                'Scheduler tick completed with %d failed task(s); returning non-zero for process restart.',
                $failures->count(),
            ));

            return self::FAILURE;
        }

        return $exitCode;
    }
}
