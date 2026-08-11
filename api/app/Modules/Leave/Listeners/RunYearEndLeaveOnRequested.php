<?php

declare(strict_types=1);

namespace App\Modules\Leave\Listeners;

use App\Common\Services\ChainListenerRunService;
use App\Modules\Auth\Models\User;
use App\Modules\Leave\Events\YearEndLeaveProcessingRequested;
use App\Modules\Leave\Jobs\ProcessYearEndLeave;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Executes a durable year-end leave request.
 *
 * The year/scope lock handles duplicate outbox publication and the actor
 * check turns deleted/misconfigured automation users into an explicit manual
 * recovery outcome instead of a successful-looking no-op.
 */
class RunYearEndLeaveOnRequested implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = ProcessYearEndLeave::TIMEOUT_SECONDS;

    /** @return array<int, WithoutOverlapping> */
    public function middleware(YearEndLeaveProcessingRequested $event): array
    {
        $scope = $event->leaveTypeIds === null
            ? 'all'
            : implode(',', $event->leaveTypeIds);

        return [
            (new WithoutOverlapping("leave-year-end:{$event->year}:".hash('sha256', $scope)))
                ->releaseAfter(30)
                ->expireAfter(ProcessYearEndLeave::TIMEOUT_SECONDS + 300),
        ];
    }

    public function handle(YearEndLeaveProcessingRequested $event): void
    {
        $runBy = User::query()
            ->whereKey($event->runById)
            ->where('is_active', true)
            ->first();
        if (! $runBy) {
            app(ChainListenerRunService::class)->recordOutcome(
                'manual_required',
                'year_end_automation_actor_unavailable',
                "Automation actor #{$event->runById} no longer exists or is inactive.",
            );
            Log::error('RunYearEndLeaveOnRequested cannot execute without its actor.', [
                'year' => $event->year,
                'run_by_id' => $event->runById,
                'request_id' => $event->requestId,
            ]);
            return;
        }

        $summary = (new ProcessYearEndLeave(
            runBy: $runBy,
            year: $event->year,
            leaveTypeIds: $event->leaveTypeIds,
        ))->handle();

        app(ChainListenerRunService::class)->recordOutcome(
            'completed',
            'year_end_leave_processed',
            sprintf(
                'Processed %d leave type(s); skipped %d already-complete type(s).',
                $summary['processed_types'],
                $summary['skipped_types'],
            ),
        );
    }

    public function failed(YearEndLeaveProcessingRequested $event, Throwable $exception): void
    {
        Log::error('RunYearEndLeaveOnRequested failed permanently.', [
            'year' => $event->year,
            'run_by_id' => $event->runById,
            'request_id' => $event->requestId,
            'error' => $exception->getMessage(),
        ]);
    }
}
