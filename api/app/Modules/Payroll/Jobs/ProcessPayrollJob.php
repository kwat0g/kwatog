<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Jobs;

use App\Modules\Auth\Models\User;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Events\PayrollProgressEvent;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollCalculatorService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use App\Modules\Payroll\Services\PayrollProgressTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Compute payroll for every active employee in a period.
 *
 * Per-employee transactions live in PayrollCalculatorService — one bad row
 * never breaks the batch. Errors are persisted to payrolls.error_message so
 * the UI can show + retry individuals.
 *
 * Concurrency: the period is claimed synchronously by
 * PayrollPeriodService::claimForCompute() BEFORE this job is dispatched, so by
 * the time we run the row is already at Processing and no second dispatch can
 * win. ShouldBeUnique is kept as belt-and-braces against a double-dispatch of
 * the same claim. This job never claims the period itself; it verifies it owns
 * one and refuses to touch anything else.
 *
 * Terminal status is Computed (never Draft) so the UI can tell "computed,
 * awaiting approval" apart from "never computed" — that conflation is what let
 * the Compute button stay live and silently re-run finished payroll.
 *
 * Deliberately NOT ShouldBeUnique. The DB claim above is an atomic conditional
 * UPDATE and therefore a strictly stronger gate. The unique lock added a real
 * failure mode on top of it: a worker killed mid-run leaves the lock behind for
 * uniqueFor seconds, and dispatch() then returns silently without enqueuing
 * anything. The period sat at Processing with no worker and no error — Compute
 * appeared to do nothing at all.
 */
class ProcessPayrollJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Allow up to 30 minutes for ~200-employee runs.
     *
     * Exposed as a const so PayrollPeriodService::staleAfterMinutes() can floor
     * the stale-claim threshold above it — a healthy long run must never be
     * reclaimed out from under its own worker.
     */
    public const TIMEOUT_SECONDS = 1800;

    public int $timeout = self::TIMEOUT_SECONDS;

    public function __construct(
        public PayrollPeriod $period,
        public ?int $triggeredBy = null,
    ) {}

    public function handle(
        PayrollCalculatorService $calculator,
        PayrollPeriodService $periods,
        PayrollProgressTracker $progress,
    ): void {
        $period = $this->period->fresh();
        if (! $period) {
            return; // period deleted between dispatch and execution
        }

        // The claim must already be ours. Anything else means the period was
        // force-unlocked, voided, or otherwise moved on while we sat in the
        // queue — recomputing it now would clobber state the user has since
        // acted on, so bail without touching a single row.
        if ($period->status !== PayrollPeriodStatus::Processing) {
            Log::info('ProcessPayrollJob: period no longer claimed for compute; skipping', [
                'period_id' => $period->id,
                'status'    => $period->status?->value,
            ]);
            return;
        }

        // REC-04 — record the maker (HR user who clicked Compute) so approve()
        // can enforce maker-checker: the computer may not also approve.
        // claimForCompute() normally stamps this; keep it for queued dispatches
        // that bypassed the HTTP path (e.g. AutoPayrollPeriodService).
        if ($this->triggeredBy !== null && $period->computed_by !== $this->triggeredBy) {
            $period->forceFill(['computed_by' => $this->triggeredBy])->save();
        }

        $employees = $periods->availableEmployees($period);
        $total     = $employees->count();
        $processed = 0;
        $failures  = 0;

        // Publish 0/total immediately so the page shows a real bar the moment
        // the worker picks the job up, rather than an indeterminate spinner
        // until the first ten employees are done.
        $emit = function () use ($progress, &$period, &$processed, &$total, &$failures): void {
            $progress->put($period, $processed, $total, $failures);
            PayrollProgressEvent::dispatch($period, $processed, $total, $failures);
        };
        $emit();

        try {
            foreach ($employees as $emp) {
                try {
                    // internal: true — the period is legitimately Processing
                    // because WE claimed it. External callers are refused that
                    // status (see PayrollCalculatorService::computeForEmployee).
                    $calculator->computeForEmployee($period, $emp, internal: true);
                } catch (Throwable $e) {
                    $failures++;
                    Log::error('Payroll computation failed for employee', [
                        'employee_id' => $emp->id,
                        'period_id'   => $period->id,
                        'message'     => $e->getMessage(),
                    ]);

                    // Stamp a failure row so the UI knows about it.
                    //
                    // A ₱0 error row is a diagnostic marker, NOT payment: it
                    // deliberately takes no pay-cycle claim. That matters most
                    // for the one failure mode that is itself about claims — an
                    // employee another period already paid for this cutoff. The
                    // marker records why they were skipped here while their real
                    // payroll stays where it was actually computed.
                    //
                    // approve() blocks on any row with an error_message, so
                    // these cannot be signed off or posted to the GL.
                    Payroll::updateOrCreate(
                        ['payroll_period_id' => $period->id, 'employee_id' => $emp->id],
                        [
                            'pay_type'         => $emp->pay_type instanceof \BackedEnum ? $emp->pay_type->value : (string) $emp->pay_type,
                            'basic_pay'        => '0.00',
                            'gross_pay'        => '0.00',
                            'total_deductions' => '0.00',
                            'net_pay'          => '0.00',
                            'error_message'    => $e->getMessage(),
                            'computed_at'      => now(),
                        ]
                    );
                }

                $processed++;
                if ($processed % 10 === 0 || $processed === $total) {
                    $emit();
                }
            }
        } finally {
            // Land on Computed — the run produced rows and is awaiting a
            // checker's approval. Parking back at Draft (the old behaviour)
            // made a finished run indistinguishable from an untouched one.
            // Draft is only correct when there is genuinely nothing to show.
            // releaseClaim() is shared with force-unlock and the stale reaper
            // so all three agree on what "finished" means.
            $periods->releaseClaim($period);
            $period = $period->fresh() ?? $period;
            // Final emit carries the terminal status, so a subscribed page
            // flips out of the processing state without waiting for a poll.
            $emit();

            // Task A9 — detect anomalies on completed period.
            try {
                app(\App\Modules\Payroll\Services\PayrollAnomalyService::class)->detect($period);
            } catch (\Throwable $e) {
                Log::warning('PayrollAnomalyService::detect failed after job', [
                    'period_id' => $period->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }

    public function failed(Throwable $e): void
    {
        // If the whole job died (queue infra issue), release the claim so the
        // period is computable again. Computed when partial rows survived,
        // Draft when nothing was written.
        //
        // Resolved via app() rather than injected: Laravel calls this hook as
        // a plain `$command->failed($e)` (CallQueuedHandler::failed) with no
        // container resolution, so a constructor-style injected argument would
        // arrive null and fatal exactly when we most need this to work.
        $period = $this->period->fresh();
        if ($period && $period->status === PayrollPeriodStatus::Processing) {
            app(PayrollPeriodService::class)->releaseClaim($period);
        }
        Log::error('ProcessPayrollJob failed catastrophically', [
            'period_id' => $this->period->id,
            'message'   => $e->getMessage(),
        ]);
    }
}
