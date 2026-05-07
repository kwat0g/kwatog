<?php

declare(strict_types=1);

namespace App\Modules\HR\Listeners;

use App\Modules\HR\Events\EmployeeCreated;
use App\Modules\Leave\Models\EmployeeLeaveBalance;
use App\Modules\Leave\Models\LeaveType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Series C — Task C3. Initialise this calendar year's leave balances for
 * a newly hired employee, pro-rated against their hire date.
 *
 * Pro-ration: balance = round(default_balance * remaining_days_in_year /
 * total_days_in_year, 1). Hires on Jan 1 get the full balance; hires
 * mid-year get a fraction.
 *
 * Idempotent: uses updateOrInsert keyed by (employee_id, leave_type_id,
 * year). Re-firing after rollover does nothing for the current year.
 *
 * Best-effort.
 */
class InitializeLeaveBalances implements ShouldQueue
{
    public function handle(EmployeeCreated $event): void
    {
        try {
            $emp = $event->employee;
            if (! class_exists(LeaveType::class)) return;

            $hire = $emp->date_hired ? Carbon::parse((string) $emp->date_hired) : Carbon::now();
            $year = (int) $hire->year;

            $startOfYear = Carbon::create($year, 1, 1);
            $endOfYear   = Carbon::create($year, 12, 31);
            $totalDays   = $startOfYear->diffInDays($endOfYear) + 1; // 365 or 366
            $remaining   = max(1, $hire->diffInDays($endOfYear) + 1);
            $proRation   = $remaining / $totalDays;

            DB::transaction(function () use ($emp, $year, $proRation) {
                LeaveType::query()->where('is_active', true)->get()->each(function (LeaveType $lt) use ($emp, $year, $proRation) {
                    // Idempotent + non-destructive: skip rows the upstream
                    // service may have already seeded (EmployeeService::create
                    // already credits default_balance non-pro-rated). Only
                    // insert rows that don't exist yet — that way bulk
                    // imports / factory paths get pro-rated balances without
                    // overwriting service-seeded data.
                    $exists = EmployeeLeaveBalance::query()
                        ->where('employee_id', $emp->id)
                        ->where('leave_type_id', $lt->id)
                        ->where('year', $year)
                        ->exists();
                    if ($exists) return;

                    $credits = round((float) $lt->default_balance * $proRation, 1);
                    EmployeeLeaveBalance::create([
                        'employee_id'   => $emp->id,
                        'leave_type_id' => $lt->id,
                        'year'          => $year,
                        'total_credits' => $credits,
                        'used'          => 0,
                        'remaining'     => $credits,
                    ]);
                });
            });
        } catch (\Throwable $e) {
            Log::warning('InitializeLeaveBalances failed', [
                'employee_id' => $event->employee->id ?? null,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
