<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Services\AutoPayrollPeriodService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Recover a payroll-period scheduler tick missed inside the active cutoff
 * window. It never creates periods outside their current half-month window;
 * older windows require an explicit --year/--month operator backfill.
 */
class ReconcileAutoPayrollPeriods extends Command
{
    protected $signature = 'payroll:reconcile-auto-periods';

    protected $description = 'Recover missing auto-created payroll periods within the active cutoff window';

    public function handle(AutoPayrollPeriodService $service): int
    {
        $now = Carbon::now();
        $created = 0;
        $failures = 0;

        if ($now->day >= 15) {
            $period = $service->createForSecondHalfOfMonth($now->year, $now->month);
            if ($period !== null) {
                $created++;
                if ($period->fresh()->status !== PayrollPeriodStatus::Processing) {
                    $this->error("Payroll period #{$period->id} exists but its durable compute request was not staged.");
                    $failures++;
                }
            }
        }

        if ($now->day <= 15) {
            $period = $service->createForFirstHalfOfMonth($now->year, $now->month);
            if ($period !== null) {
                $created++;
                if ($period->fresh()->status !== PayrollPeriodStatus::Processing) {
                    $this->error("Payroll period #{$period->id} exists but its durable compute request was not staged.");
                    $failures++;
                }
            }
        }

        $this->info("Payroll period reconciliation complete: created={$created} failures={$failures}.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
