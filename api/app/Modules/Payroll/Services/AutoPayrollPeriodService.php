<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Jobs\ProcessPayrollJob;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Task A3 — Auto-create the next payroll period and queue computation.
 *
 * Schedule:
 *  - 14th 23:00          → create second-half period (16th–end of month)
 *  - last day 23:00      → create first-half period of next month (1st–15th)
 *
 * Idempotency: if a period with the computed period_start already exists,
 * the call is a no-op and returns null.
 */
class AutoPayrollPeriodService
{
    public function createForSecondHalfOfCurrentMonth(?Carbon $now = null): ?PayrollPeriod
    {
        $now ??= Carbon::now();
        $start = $now->copy()->day(16)->startOfDay();
        $end   = $now->copy()->endOfMonth()->startOfDay();
        $payDate = $now->copy()->endOfMonth()->startOfDay();

        return $this->createPeriod($start, $end, $payDate, isFirstHalf: false);
    }

    public function createForFirstHalfOfNextMonth(?Carbon $now = null): ?PayrollPeriod
    {
        $now ??= Carbon::now();
        $start = $now->copy()->addMonth()->day(1)->startOfDay();
        $end   = $now->copy()->addMonth()->day(15)->startOfDay();
        $payDate = $end->copy();

        return $this->createPeriod($start, $end, $payDate, isFirstHalf: true);
    }

    private function createPeriod(Carbon $start, Carbon $end, Carbon $payDate, bool $isFirstHalf): ?PayrollPeriod
    {
        if (PayrollPeriod::where('period_start', $start->toDateString())->exists()) {
            Log::info('AutoPayrollPeriodService: period already exists, skipping', ['period_start' => $start->toDateString()]);
            return null;
        }

        return DB::transaction(function () use ($start, $end, $payDate, $isFirstHalf) {
            $period = PayrollPeriod::create([
                'period_start'        => $start->toDateString(),
                'period_end'          => $end->toDateString(),
                'payroll_date'        => $payDate->toDateString(),
                'is_first_half'       => $isFirstHalf,
                'is_thirteenth_month' => false,
                'status'              => PayrollPeriodStatus::Draft->value,
                'created_by'          => null,
                'is_auto_created'     => true,
                'auto_created_at'     => now(),
            ]);

            // Queue payroll computation. The job notifies HR on completion.
            DB::afterCommit(fn () => ProcessPayrollJob::dispatch($period, null));

            return $period;
        });
    }
}
