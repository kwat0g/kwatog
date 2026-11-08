<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Services\AutoPayrollPeriodService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Recover a missed auto-period scheduler tick and queue any ended auto-created
 * cutoff. It never creates periods outside their current half-month window;
 * older windows require an explicit --year/--month operator backfill.
 */
class ReconcileAutoPayrollPeriods extends Command
{
    protected $signature = 'payroll:reconcile-auto-periods';

    protected $description = 'Recover auto-created payroll periods and queue ended cutoffs';

    public function handle(AutoPayrollPeriodService $service): int
    {
        $now = Carbon::now();
        $created = 0;
        $failures = 0;

        if ($now->day >= 15) {
            $period = $service->createForSecondHalfOfMonth($now->year, $now->month);
            if ($period !== null) {
                $created++;
                $fresh = $period->fresh();
                $stillInCutoff = $fresh->period_end->copy()->startOfDay()->gte($now->copy()->startOfDay());
                if ($fresh->status !== PayrollPeriodStatus::Processing
                    && ! ($fresh->status === PayrollPeriodStatus::Draft && $stillInCutoff)) {
                    $this->error("Payroll period #{$period->id} exists but its durable compute request was not staged.");
                    $failures++;
                }
            }
        }

        if ($now->day <= 15) {
            $period = $service->createForFirstHalfOfMonth($now->year, $now->month);
            if ($period !== null) {
                $created++;
                $fresh = $period->fresh();
                $stillInCutoff = $fresh->period_end->copy()->startOfDay()->gte($now->copy()->startOfDay());
                if ($fresh->status !== PayrollPeriodStatus::Processing
                    && ! ($fresh->status === PayrollPeriodStatus::Draft && $stillInCutoff)) {
                    $this->error("Payroll period #{$period->id} exists but its durable compute request was not staged.");
                    $failures++;
                }
            }
        }

        $staging = $service->stageDueAutoPeriods($now);
        $failures += $staging['failures'];

        $this->info(sprintf(
            'Payroll period reconciliation complete: created=%d staged=%d failures=%d.',
            $created,
            $staging['staged'],
            $failures,
        ));

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
