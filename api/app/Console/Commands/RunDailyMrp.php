<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\MRP\Enums\MrpRunStatus;
use App\Modules\MRP\Enums\MrpRunTrigger;
use App\Modules\MRP\Services\MrpAutomationService;
use Illuminate\Console\Command;

/**
 * Task A1 — Daily MRP run, scheduled at 06:00 in routes/console.php.
 */
class RunDailyMrp extends Command
{
    protected $signature = 'mrp:run-daily';

    protected $description = 'Re-run MRP across all active sales orders (Task A1)';

    public function handle(MrpAutomationService $automation): int
    {
        $this->info('Starting daily MRP run...');

        $run = $automation->run(null, MrpRunTrigger::Scheduled, null, 'daily_fallback');

        $this->info(sprintf(
            'Daily MRP run %s — evaluated %d SOs, %d shortages, %d PRs created, %d PRs updated, %dms',
            $run->status?->value ?? 'unknown',
            $run->sales_orders_evaluated,
            $run->shortages_found,
            $run->prs_created,
            $run->prs_updated,
            $run->duration_ms ?? 0,
        ));

        if ($run->status === MrpRunStatus::Failed) {
            return self::FAILURE;
        }

        // A partial run is an expected warning when at least one SO planned
        // successfully; the alert and run history carry the failed SO details.
        // Treat an all-failed partial run as a command failure so monitoring
        // still distinguishes useful work from a total planning outage.
        if (
            $run->status === MrpRunStatus::Partial
            && (int) $run->sales_orders_evaluated > 0
            && (int) $run->plans_generated === 0
        ) {
            return self::FAILURE;
        }

        if ($run->status === MrpRunStatus::Completed) {
            return self::SUCCESS;
        }

        return $run->status === MrpRunStatus::Partial
            && (int) $run->plans_generated > 0
            ? self::SUCCESS
            : self::FAILURE;
    }
}
