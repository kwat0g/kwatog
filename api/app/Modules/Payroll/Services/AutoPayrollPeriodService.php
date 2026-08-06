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
 * Idempotency: if a period already covers the computed window, the call is a
 * no-op and returns null.
 *
 * Auto-created periods are always company-wide. If HR has already created a
 * SCOPED period for the same cutoff, auto-creation stands down entirely rather
 * than adding a company-wide run on top of it — that would try to pay the
 * scoped period's employees a second time (rejected per-row by the cycle-claim
 * guard, but as a batch full of failures HR then has to unpick).
 */
class AutoPayrollPeriodService
{
    public function __construct(private readonly PayrollPeriodService $periods) {}

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

        // Any other period overlapping this window — scoped or not — means a
        // human has already taken charge of this cutoff. Adding a company-wide
        // run alongside it would double-pay whoever it covers.
        $overlapping = PayrollPeriod::query()
            ->where('is_thirteenth_month', false)
            ->where('status', '!=', PayrollPeriodStatus::Voided->value)
            ->where('period_start', '<=', $end->toDateString())
            ->where('period_end', '>=', $start->toDateString())
            ->first();

        if ($overlapping) {
            Log::warning('AutoPayrollPeriodService: an existing period overlaps this window; skipping auto-creation', [
                'period_start'        => $start->toDateString(),
                'period_end'          => $end->toDateString(),
                'existing_period_id'  => $overlapping->id,
                'existing_label'      => $overlapping->label(),
            ]);
            return null;
        }

        $period = DB::transaction(function () use ($start, $end, $payDate, $isFirstHalf) {
            $period = PayrollPeriod::create([
                'period_start'        => $start->toDateString(),
                'period_end'          => $end->toDateString(),
                'payroll_date'        => $payDate->toDateString(),
                'is_first_half'       => $isFirstHalf,
                'is_thirteenth_month' => false,
                'created_by'          => null,
                'is_auto_created'     => true,
                'auto_created_at'     => now(),
            ]);
            $period->forceFill(['status' => PayrollPeriodStatus::Draft->value])->save();

            return $period;
        });

        // Claim BEFORE dispatching. ProcessPayrollJob refuses to touch a period
        // that is not already Processing (it verifies it owns a claim rather
        // than making one), so dispatching a Draft period queued a job that
        // logged "no longer claimed" and returned without computing anything —
        // auto-payroll silently produced nothing at all.
        //
        // Claiming can legitimately fail (e.g. the scope matches nobody), so a
        // failure is logged and the period is left at Draft for HR to handle by
        // hand rather than throwing out of a scheduled command.
        try {
            $claimed = $this->periods->claimForCompute($period);
        } catch (\Throwable $e) {
            Log::warning('AutoPayrollPeriodService: period created but compute could not be claimed', [
                'period_id' => $period->id,
                'error'     => $e->getMessage(),
            ]);

            return $period;
        }

        ProcessPayrollJob::dispatch($claimed, null);

        return $claimed;
    }
}
