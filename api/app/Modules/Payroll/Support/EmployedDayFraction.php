<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support;

use Carbon\CarbonInterface;

/**
 * The one employment-window proration shared by payroll and final pay (HR-02).
 *
 * What fraction of a cutoff's CALENDAR days was the employee employed? The
 * answer scales a flat per-cutoff basic: '1.0000' for a full cutoff — the
 * overwhelmingly common case, and the value that keeps an unchanged run
 * byte-identical to the pre-proration behaviour.
 *
 * Both ends are handled:
 *
 *   hire date inside the cutoff        → paid from the hire date onward
 *   separation date inside the cutoff  → paid up to the last working day
 *
 * The separation half matters because basic pay is FLAT (migration 0437
 * retired the days-worked daily type). Someone who resigns on day 3 of a
 * 1–15 cutoff used to earn 3 × daily_rate; without this they would bank the
 * entire half-month. FinalPayService::lastSalaryProRated() reads
 * payroll.basic_pay verbatim when a computed row exists, so the inflated
 * figure would flow straight into final pay — roughly ₱6,880 on a ₱9,460
 * cutoff, per separation.
 *
 * Pure calendar-day math — no database reads. Callers resolve the
 * separation date (payroll: earliest clearances.separation_date; final pay:
 * the clearance being computed) and the period window, then delegate here.
 * HR's no-payroll-row fallback must use THIS fraction, never a re-derived
 * attendance-day formula: the two disagreeing is exactly the HR-02 defect
 * (₱22,000 monthly, Mar 1–15 cutoff, separation Mar 10 → attendance-day
 * fallback ₱8,000 vs payroll-basis ₱7,332.60).
 */
final class EmployedDayFraction
{
    public static function of(
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        ?CarbonInterface $dateHired,
        ?CarbonInterface $separationDate,
    ): string {
        $start = $periodStart->copy()->startOfDay();
        $end   = $periodEnd->copy()->startOfDay();

        $from = $dateHired !== null && $dateHired->copy()->startOfDay()->gt($start)
            ? $dateHired->copy()->startOfDay()
            : $start;

        $to = $separationDate !== null && $separationDate->copy()->startOfDay()->lt($end)
            ? $separationDate->copy()->startOfDay()
            : $end;

        // Employment window does not overlap the cutoff at all (hired after it
        // ended, or separated before it began). Nothing is owed.
        if ($to->lt($from)) {
            return '0.0000';
        }

        $totalDays   = max(1, $start->diffInDays($end, true) + 1);
        $coveredDays = $from->diffInDays($to, true) + 1;

        if ($coveredDays >= $totalDays) {
            return '1.0000';
        }

        return bcdiv((string) $coveredDays, (string) $totalDays, 4);
    }
}
