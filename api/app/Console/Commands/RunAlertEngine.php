<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\AlertEngineService;
use Illuminate\Console\Command;

/**
 * Task A2 — Scheduled every 15 minutes via routes/console.php.
 */
class RunAlertEngine extends Command
{
    protected $signature   = 'alerts:run';
    protected $description = 'Run all alert engine threshold checks (Task A2)';

    public function handle(AlertEngineService $engine): int
    {
        $start = microtime(true);
        $stats = $engine->runAllChecks();
        $ms    = (int) round((microtime(true) - $start) * 1000);

        $this->info("Alert engine completed in {$ms}ms — raised {$stats['raised']} new alerts.");
        if (! empty($stats['by_severity'])) {
            $this->line('Recent by severity: '.json_encode($stats['by_severity']));
        }
        return self::SUCCESS;
    }
}
