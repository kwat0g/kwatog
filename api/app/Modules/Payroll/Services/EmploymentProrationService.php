<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\HR\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The ONE employment-window proration (HR-02).
 *
 * Payroll's calculator and HR's final pay used to each carry a private
 * employedDayFraction with different formulas — calendar-day flat here,
 * DTR-day-equivalents there — so the same leaver got different money
 * depending on which code path ran. Both now delegate here.
 *
 * Returns the fraction of a period's CALENDAR days the employee was employed,
 * as a scale-4 bcmath string ('1.0000' full, '0.0000' no overlap). Attendance
 * is deliberately ignored: basic pay is flat per cutoff (migration 0437), so
 * only the employment window — not DTR rows — decides what fraction of it is
 * owed.
 */
class EmploymentProrationService
{
    public function employedDayFraction(Employee $employee, CarbonInterface $periodStart, CarbonInterface $periodEnd): string
    {
        $from = $employee->date_hired && $employee->date_hired->gt($periodStart)
            ? $employee->date_hired
            : $periodStart;

        $separationDate = $this->separationDate($employee);
        $to = $separationDate && $separationDate->lt($periodEnd)
            ? $separationDate
            : $periodEnd;

        // Employment window does not overlap the cutoff at all (hired after it
        // ended, or separated before it began). Nothing is owed.
        if ($to->lt($from)) {
            return '0.0000';
        }

        $totalDays = max(1, $periodStart->diffInDays($periodEnd, true) + 1);
        $coveredDays = $from->diffInDays($to, true) + 1;

        if ($coveredDays >= $totalDays) {
            return '1.0000';
        }

        return bcdiv((string) $coveredDays, (string) $totalDays, 4);
    }

    /**
     * The employee's last working day, if a separation is on record.
     *
     * Read from clearances.separation_date — the authoritative last day, set when
     * the separation is initiated. Guarded so the service keeps working if the
     * clearance table is absent, and takes the EARLIEST separation date on record
     * so a re-initiated separation cannot extend paid days.
     */
    private function separationDate(Employee $employee): ?Carbon
    {
        if (! Schema::hasTable('clearances')) {
            return null;
        }

        $date = DB::table('clearances')
            ->where('employee_id', $employee->id)
            ->whereNull('deleted_at')
            ->whereNotNull('separation_date')
            ->min('separation_date');

        return $date === null ? null : Carbon::parse($date)->startOfDay();
    }
}
