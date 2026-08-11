<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Database\QueryException;
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

        return $this->createForSecondHalfOfMonth($now->year, $now->month);
    }

    public function createForFirstHalfOfNextMonth(?Carbon $now = null): ?PayrollPeriod
    {
        $now ??= Carbon::now();

        $target = $now->copy()->addMonthNoOverflow();

        return $this->createForFirstHalfOfMonth($target->year, $target->month);
    }

    public function createForSecondHalfOfMonth(int $year, int $month): ?PayrollPeriod
    {
        $target = $this->targetMonth($year, $month);
        $start = $target->copy()->day(16)->startOfDay();
        $end = $target->copy()->endOfMonth()->startOfDay();

        return $this->createPeriod($start, $end, $end->copy(), isFirstHalf: false);
    }

    public function createForFirstHalfOfMonth(int $year, int $month): ?PayrollPeriod
    {
        $start = $this->targetMonth($year, $month);
        $end = $start->copy()->day(15)->startOfDay();

        return $this->createPeriod($start, $end, $end->copy(), isFirstHalf: true);
    }

    private function createPeriod(Carbon $start, Carbon $end, Carbon $payDate, bool $isFirstHalf): ?PayrollPeriod
    {
        $autoKey = $this->autoIdempotencyKey($start);

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
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'existing_period_id' => $overlapping->id,
                'existing_label' => $overlapping->label(),
            ]);

            return null;
        }

        try {
            $period = DB::transaction(function () use ($start, $end, $payDate, $isFirstHalf, $autoKey) {
                $period = PayrollPeriod::create([
                    'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(),
                    'payroll_date' => $payDate->toDateString(),
                    'is_first_half' => $isFirstHalf,
                    'is_thirteenth_month' => false,
                    'created_by' => null,
                    'is_auto_created' => true,
                    'auto_created_at' => now(),
                    'auto_idempotency_key' => $autoKey,
                ]);
                $period->forceFill(['status' => PayrollPeriodStatus::Draft->value])->save();

                return $period;
            });
        } catch (QueryException $e) {
            if (! $this->isAutoKeyConflict($e)) {
                throw $e;
            }

            Log::info('AutoPayrollPeriodService: concurrent auto-period creation lost the idempotency race; skipping', [
                'auto_idempotency_key' => $autoKey,
            ]);

            return null;
        }

        // Claim and stage the durable compute request together. ProcessPayrollJob
        // refuses to touch a period that is not already Processing (it verifies
        // it owns a claim rather than making one), so a Draft period must never
        // produce a compute request.
        //
        // Claiming can legitimately fail (e.g. the scope matches nobody), so a
        // failure is logged and the period is left at Draft for HR to handle by
        // hand rather than throwing out of a scheduled command.
        try {
            $claimed = $this->periods->claimForComputeAndStage($period);
        } catch (\Throwable $e) {
            Log::warning('AutoPayrollPeriodService: period created but compute could not be claimed', [
                'period_id' => $period->id,
                'error' => $e->getMessage(),
            ]);

            return $period;
        }

        return $claimed;
    }

    private function autoIdempotencyKey(Carbon $start): string
    {
        return $start->toDateString().':regular';
    }

    private function targetMonth(int $year, int $month): Carbon
    {
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            throw new \InvalidArgumentException('Payroll period target must be a year from 2000..2100 and a month from 1..12.');
        }

        return Carbon::create($year, $month, 1)->startOfDay();
    }

    private function isAutoKeyConflict(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return in_array((string) $exception->getCode(), ['23505', '23000'], true)
            && (str_contains($message, 'auto_idempotency_key')
                || str_contains($message, 'payroll_periods_auto_idempotency_unique'));
    }
}
