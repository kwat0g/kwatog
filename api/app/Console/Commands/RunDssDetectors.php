<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunDssDetectorJob;
use App\Modules\DSS\Services\DetectorRegistry;
use App\Modules\DSS\Services\DssParameterService;
use Illuminate\Console\Command;

/**
 * DSS Wave 0-2 — Scheduled hourly via routes/console.php.
 * Queues one bounded worker job per registered detector.
 */
class RunDssDetectors extends Command
{
    protected $signature = 'dss:run {--detector= : Run a single detector id} {--limit= : Max emissions per detector}';

    protected $description = 'Run DSS detectors and persist recommendations (shadow-safe)';

    public function handle(DetectorRegistry $registry, DssParameterService $params): int
    {
        /** @var string|null $only */
        $only = $this->option('detector') ?: null;
        if (! $params->engineEnabled()) {
            // The kill switch is an operator decision, not a failure — but it
            // must never look like a healthy idle run, so say so explicitly.
            $this->warn('DSS engine disabled via kill switch (dss.engine.enabled=false); nothing ran.');

            return self::SUCCESS;
        }

        if ($only !== null && ! $registry->has($only)) {
            $this->error("Unknown DSS detector: {$only}");

            return self::FAILURE;
        }

        $ids = $only === null ? array_keys($registry->all()) : [$only];
        $limit = $this->option('limit') === null ? null : max(1, min(10000, (int) $this->option('limit')));
        foreach ($ids as $id) {
            RunDssDetectorJob::dispatch($id, $limit);
            $this->line("{$id}: queued");
        }

        return self::SUCCESS;
    }
}
