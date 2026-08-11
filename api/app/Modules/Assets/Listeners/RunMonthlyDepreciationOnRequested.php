<?php

declare(strict_types=1);

namespace App\Modules\Assets\Listeners;

use App\Common\Services\ChainListenerRunService;
use App\Modules\Assets\Events\MonthlyDepreciationRequested;
use App\Modules\Assets\Jobs\RunMonthlyDepreciationJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Executes one durable asset-depreciation period request. */
class RunMonthlyDepreciationOnRequested implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = RunMonthlyDepreciationJob::TIMEOUT_SECONDS;

    /** @return array<int, WithoutOverlapping> */
    public function middleware(MonthlyDepreciationRequested $event): array
    {
        return [
            (new WithoutOverlapping("assets:depreciation:{$event->year}-{$event->month}"))
                ->releaseAfter(30)
                ->expireAfter(RunMonthlyDepreciationJob::TIMEOUT_SECONDS + 300),
        ];
    }

    public function handle(MonthlyDepreciationRequested $event): void
    {
        app()->call([
            new RunMonthlyDepreciationJob($event->year, $event->month),
            'handle',
        ]);

        app(ChainListenerRunService::class)->recordOutcome(
            'completed',
            'monthly_depreciation_processed',
            sprintf('Asset depreciation for %04d-%02d completed.', $event->year, $event->month),
        );
    }

    public function failed(MonthlyDepreciationRequested $event, Throwable $exception): void
    {
        Log::error('RunMonthlyDepreciationOnRequested failed permanently.', [
            'year' => $event->year,
            'month' => $event->month,
            'request_id' => $event->requestId,
            'error' => $exception->getMessage(),
        ]);
    }
}
