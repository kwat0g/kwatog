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
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
 */
class ProcessPayrollJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Allow up to 30 minutes for ~200-employee runs. */
    public int $timeout = 1800;

    public int $uniqueFor = 1800;

    public function __construct(
        public PayrollPeriod $period,
        public ?int $triggeredBy = null,
    ) {}

    public function uniqueId(): string
    {
        return "payroll-period-{$this->period->id}";
    }

    public function handle(
        PayrollCalculatorService $calculator,
        PayrollPeriodService $periods,
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

        try {
            foreach ($employees as $emp) {
                try {
                    $calculator->computeForEmployee($period, $emp);
                } catch (Throwable $e) {
                    $failures++;
                    Log::error('Payroll computation failed for employee', [
                        'employee_id' => $emp->id,
                        'period_id'   => $period->id,
                        'message'     => $e->getMessage(),
                    ]);

                    // Stamp a failure row so the UI knows about it.
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
                    PayrollProgressEvent::dispatch($period, $processed, $total, $failures);
                }
            }
        } finally {
            // Land on Computed — the run produced rows and is awaiting a
            // checker's approval. Parking back at Draft (the old behaviour)
            // made a finished run indistinguishable from an untouched one.
            // Draft is only correct when there is genuinely nothing to show.
            $period->forceFill([
                'status' => $period->payrolls()->exists()
                    ? PayrollPeriodStatus::Computed->value
                    : PayrollPeriodStatus::Draft->value,
                'processing_started_at' => null,
            ])->save();
            PayrollProgressEvent::dispatch($period, $processed, $total, $failures);

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
        $period = $this->period->fresh();
        if ($period && $period->status === PayrollPeriodStatus::Processing) {
            $period->forceFill([
                'status' => $period->payrolls()->exists()
                    ? PayrollPeriodStatus::Computed->value
                    : PayrollPeriodStatus::Draft->value,
                'processing_started_at' => null,
            ])->save();
        }
        Log::error('ProcessPayrollJob failed catastrophically', [
            'period_id' => $this->period->id,
            'message'   => $e->getMessage(),
        ]);
    }
}
