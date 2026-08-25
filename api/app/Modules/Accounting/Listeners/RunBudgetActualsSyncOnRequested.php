<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Listeners;

use App\Common\Services\ChainListenerRunService;
use App\Modules\Accounting\Events\BudgetActualsSyncRequested;
use App\Modules\Accounting\Jobs\SyncBudgetActuals;
use App\Modules\Accounting\Models\BudgetActualsSyncRun;
use App\Modules\Accounting\Services\BudgetConsumptionService;
use App\Modules\Accounting\Services\BudgetFiscalYearResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Executes the durable, rerunnable budget actuals rebuild. */
class RunBudgetActualsSyncOnRequested implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = SyncBudgetActuals::TIMEOUT_SECONDS;

    /** @return array<int, WithoutOverlapping> */
    public function middleware(BudgetActualsSyncRequested $event): array
    {
        return [
            (new WithoutOverlapping('budget-actuals:'.($event->fiscalYearId ?? 'active')))
                ->releaseAfter(30)
                ->expireAfter(SyncBudgetActuals::TIMEOUT_SECONDS + 300),
        ];
    }

    public function handle(
        BudgetActualsSyncRequested $event,
        BudgetConsumptionService $consumption,
        BudgetFiscalYearResolver $fiscalYears,
    ): void
    {
        (new SyncBudgetActuals($event->fiscalYearId, $event->requestId))->handle($consumption, $fiscalYears);

        app(ChainListenerRunService::class)->recordOutcome(
            'completed',
            'budget_actuals_synced',
            $event->fiscalYearId === null
                ? 'The active fiscal year budget actuals were rebuilt.'
                : "Fiscal year {$event->fiscalYearId} budget actuals were rebuilt.",
        );
    }

    public function failed(BudgetActualsSyncRequested $event, Throwable $exception): void
    {
        BudgetActualsSyncRun::query()
            ->where('request_id', $event->requestId)
            ->update([
                'status' => BudgetActualsSyncRun::STATUS_FAILED,
                'last_error' => mb_substr($exception->getMessage(), 0, 10000),
                'failed_at' => now(),
                'updated_at' => now(),
            ]);
        Log::error('RunBudgetActualsSyncOnRequested failed permanently.', [
            'fiscal_year_id' => $event->fiscalYearId,
            'request_id' => $event->requestId,
            'error' => $exception->getMessage(),
        ]);
    }
}
