<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Listeners;

use App\Common\Services\ChainListenerRunService;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Events\PayrollComputationRequested;
use App\Modules\Payroll\Jobs\ProcessPayrollJob;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollCalculatorService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Payroll\Services\PayrollProgressTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Execute a durable payroll computation request.
 *
 * The outbox may publish the same request again after a worker dies between
 * queueing this listener and marking the outbox message published. A
 * per-period overlap lock serializes those deliveries; the authoritative
 * status check then makes a completed/released period a safe no-op.
 */
class RunPayrollComputationOnRequested implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = ProcessPayrollJob::TIMEOUT_SECONDS;

    /** @return array<int, WithoutOverlapping> */
    public function middleware(PayrollComputationRequested $event): array
    {
        return [
            (new WithoutOverlapping("payroll-period-compute:{$event->period->id}"))
                ->releaseAfter(30)
                ->expireAfter(ProcessPayrollJob::TIMEOUT_SECONDS + 300),
        ];
    }

    public function handle(
        PayrollComputationRequested $event,
        PayrollCalculatorService $calculator,
        PayrollPeriodService $periods,
        PayrollProgressTracker $progress,
    ): void {
        $period = PayrollPeriod::query()->find($event->period->id);
        if (! $period) {
            app(ChainListenerRunService::class)->recordOutcome(
                'skipped',
                'payroll_period_missing',
            );
            return;
        }

        // A duplicate publication, a stale replay, or an operator unlock may
        // have moved the period on while this request was waiting in the queue.
        // Never let that old request rewrite a later payroll state.
        if (! $periods->claimIsOwned($period, $event->claimToken)) {
            app(ChainListenerRunService::class)->recordOutcome(
                'skipped',
                'payroll_period_not_processing',
                'The payroll period is no longer claimed for computation.',
            );
            return;
        }

        (new ProcessPayrollJob($period, $event->triggeredBy, $event->claimToken))
            ->handle($calculator, $periods, $progress);

        $finished = $period->fresh();
        if ($finished?->status === PayrollPeriodStatus::Processing
            && ! $periods->claimIsOwned($finished, $event->claimToken)) {
            app(ChainListenerRunService::class)->recordOutcome(
                'skipped',
                'payroll_compute_claim_taken_over',
                'The payroll compute claim was taken over before this request completed.',
            );

            return;
        }
        if ($finished?->status === PayrollPeriodStatus::Computed) {
            app(ChainListenerRunService::class)->recordOutcome(
                'completed',
                'payroll_computation_completed',
                "Payroll period {$period->id} was computed and is awaiting approval.",
            );
            return;
        }

        app(ChainListenerRunService::class)->recordOutcome(
            'completed',
            'payroll_computation_released',
            'The payroll computation request finished without payroll rows; the period remains available for review.',
        );
    }

    /**
     * Preserve the compute job's catastrophic-failure recovery when Laravel
     * dead-letters the queued listener after its final retry.
     */
    public function failed(PayrollComputationRequested $event, Throwable $exception): void
    {
        (new ProcessPayrollJob($event->period, $event->triggeredBy, $event->claimToken))->failed($exception);

        Log::error('RunPayrollComputationOnRequested failed permanently', [
            'period_id' => $event->period->id,
            'request_id' => $event->requestId,
            'error' => $exception->getMessage(),
        ]);
    }
}
