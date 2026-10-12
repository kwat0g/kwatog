<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\DSS\Services\DssRunnerService;
use Illuminate\Console\Command;

/**
 * DSS Wave 0-2 — Scheduled hourly via routes/console.php.
 * Runs every enabled detector; one failure never stops the others, but any
 * failure exits non-zero (same convention as alerts:run).
 */
class RunDssDetectors extends Command
{
    protected $signature = 'dss:run {--detector= : Run a single detector id} {--limit= : Max emissions per detector}';

    protected $description = 'Run DSS detectors and persist recommendations (shadow-safe)';

    public function handle(DssRunnerService $runner): int
    {
        $context = [];
        if ($this->option('limit') !== null) {
            $context['limit'] = max(1, (int) $this->option('limit'));
        }

        /** @var string|null $only */
        $only = $this->option('detector') ?: null;
        $outcome = $runner->run($only, $context);

        if ($outcome['engine'] === 'disabled') {
            // The kill switch is an operator decision, not a failure — but it
            // must never look like a healthy idle run, so say so explicitly.
            $this->warn('DSS engine disabled via kill switch (dss.engine.enabled=false); nothing ran.');

            return self::SUCCESS;
        }

        foreach ($outcome['detectors'] as $id => $result) {
            $this->line("{$id}: {$result['status']} emitted={$result['emitted']} updated={$result['updated']}");
        }

        if ($outcome['failed'] !== []) {
            $this->error('DSS detectors failed: '.implode(', ', $outcome['failed']));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
