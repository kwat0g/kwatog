<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Accounting\Jobs\SyncBudgetActuals;
use Illuminate\Console\Command;

/**
 * Dispatch the SyncBudgetActuals job, optionally for a specific fiscal year.
 *
 * Usage:
 *   php artisan budget:sync-actuals
 *   php artisan budget:sync-actuals --fiscal-year=42
 */
class SyncBudgetActualsCommand extends Command
{
    protected $signature = 'budget:sync-actuals {--fiscal-year= : Optional fiscal year ID to sync}';

    protected $description = 'Sync GL actuals from posted journal entries into budget line items.';

    public function handle(): int
    {
        $fiscalYearId = $this->option('fiscal-year');
        $fiscalYearId = $fiscalYearId !== null ? (int) $fiscalYearId : null;

        SyncBudgetActuals::dispatch($fiscalYearId);

        $msg = $fiscalYearId
            ? "Dispatched SyncBudgetActuals for fiscal year {$fiscalYearId}."
            : 'Dispatched SyncBudgetActuals for the current active fiscal year.';

        $this->info($msg);

        return self::SUCCESS;
    }
}
