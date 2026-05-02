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
 * Concurrency: ShouldBeUnique on the period prevents two HR users hitting
 * the Compute button at the same time. The lock auto-releases on completion
 * or after the timeout grace.
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
        if (! $period || $period->status === PayrollPeriodStatus::Finalized) {
            return; // nothing to do
        }
        if ($period->status === PayrollPeriodStatus::Processing) {
            // Another worker may already own it; bail.
            return;
        }

        $period->status = PayrollPeriodStatus::Processing;
        $period->save();

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
            $period->status = PayrollPeriodStatus::Draft;
            $period->save();
            PayrollProgressEvent::dispatch($period, $processed, $total, $failures);
        }
    }

    public function failed(Throwable $e): void
    {
        // If the whole job died (queue infra issue), park the period back in draft.
        $period = $this->period->fresh();
        if ($period && $period->status === PayrollPeriodStatus::Processing) {
            $period->status = PayrollPeriodStatus::Draft;
            $period->save();
        }
        Log::error('ProcessPayrollJob failed catastrophically', [
            'period_id' => $this->period->id,
            'message'   => $e->getMessage(),
        ]);
    }
}
