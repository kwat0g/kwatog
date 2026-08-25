<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Accounting\Services\BudgetActualsSyncService;
use Illuminate\Validation\ValidationException;
use App\Common\Exceptions\BusinessRuleException;
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

    public function handle(BudgetActualsSyncService $actualsSync): int
    {
        $fiscalYearId = $this->option('fiscal-year');
        $fiscalYearId = $fiscalYearId === null || $fiscalYearId === ''
            ? null
            : (ctype_digit((string) $fiscalYearId) ? (int) $fiscalYearId : 0);

        try {
            $outbox = $actualsSync->request($fiscalYearId);
        } catch (ValidationException|BusinessRuleException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $run = $actualsSync->runForOutbox((string) $outbox->getKey());
        $runId = $run?->getKey() ?? '—';

        $msg = $fiscalYearId
            ? "Staged durable budget actuals sync for fiscal year {$fiscalYearId} (run {$runId}, outbox {$outbox->getKey()})."
            : "Staged durable budget actuals sync for the current active fiscal year (run {$runId}, outbox {$outbox->getKey()}).";

        $this->info($msg);

        return self::SUCCESS;
    }
}
