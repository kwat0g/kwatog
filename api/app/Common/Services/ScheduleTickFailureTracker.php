<?php

declare(strict_types=1);

namespace App\Common\Services;

use Illuminate\Console\Events\ScheduledTaskFailed;

/**
 * Tracks failures emitted while one Artisan scheduler tick is running.
 *
 * Laravel's native schedule:run reports ScheduledTaskFailed but intentionally
 * keeps the command successful so one failed task does not stop other due
 * tasks. The production wrapper uses this tracker to preserve that isolation
 * while making the tick's process status truthful to Docker.
 */
final class ScheduleTickFailureTracker
{
    private int $failures = 0;

    public function reset(): void
    {
        $this->failures = 0;
    }

    public function record(ScheduledTaskFailed $event): void
    {
        $this->failures++;
    }

    public function count(): int
    {
        return $this->failures;
    }
}
