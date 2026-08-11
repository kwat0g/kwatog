<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Listeners;

use App\Common\Services\ChainListenerRunService;
use App\Modules\Accounting\Events\BudgetActualsSyncRequested;
use App\Modules\Accounting\Jobs\SyncBudgetActuals;
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

    public function handle(BudgetActualsSyncRequested $event): void
    {
        (new SyncBudgetActuals($event->fiscalYearId))->handle();

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
        Log::error('RunBudgetActualsSyncOnRequested failed permanently.', [
            'fiscal_year_id' => $event->fiscalYearId,
            'request_id' => $event->requestId,
            'error' => $exception->getMessage(),
        ]);
    }
}
