<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Exceptions\InsufficientLeaveBalanceException;
use App\Modules\Leave\Models\EmployeeLeaveBalance;
use App\Modules\Leave\Models\LeaveType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LeaveBalanceService
{
    /** Ensure all active leave-type balances exist for an employee for a given year. */
    public function seedFor(int $employeeId, int $year): void
    {
        DB::transaction(function () use ($employeeId, $year) {
            LeaveType::where('is_active', true)->get()->each(function (LeaveType $lt) use ($employeeId, $year) {
                EmployeeLeaveBalance::firstOrCreate(
                    ['employee_id' => $employeeId, 'leave_type_id' => $lt->id, 'year' => $year],
                    ['total_credits' => $lt->default_balance, 'used' => 0, 'remaining' => $lt->default_balance],
                );
            });
        });
    }

    /**
     * LV-02 — seed a new hire's balances for the hire year, pro-rated against
     * the hire date: total_credits = round(default_balance × remaining_days /
     * days_in_year, 1), remaining counted hire-date-through-Dec-31 inclusive.
     * A Jan 1 hire therefore gets the full entitlement.
     *
     * Hires dated in a PRIOR calendar year (historical data entry) get the
     * full default_balance for the CURRENT year — no retroactive pro-ration.
     *
     * Insert-if-absent keyed by (employee_id, leave_type_id, year): rows that
     * already exist (leave consumed, re-hire, earlier seed) are never
     * clobbered, and the unique key atomically rejects duplicate events.
     *
     * @return int number of balance rows inserted
     */
    public function seedProratedFor(Employee $employee): int
    {
        $hire = $employee->date_hired
            ? Carbon::parse((string) $employee->date_hired)
            : Carbon::now();
        $currentYear = (int) Carbon::now()->format('Y');

        $year = (int) $hire->format('Y');
        $proRation = 1.0;
        if ($year < $currentYear) {
            $year = $currentYear;
        } else {
            $startOfYear = Carbon::create($year, 1, 1);
            $endOfYear = Carbon::create($year, 12, 31);
            $totalDays = $startOfYear->diffInDays($endOfYear, true) + 1; // 365 or 366
            $remaining = max(1, $hire->diffInDays($endOfYear, true) + 1);
            $proRation = $remaining / $totalDays;
        }

        $created = 0;
        DB::transaction(function () use ($employee, $year, $proRation, &$created): void {
            LeaveType::query()->where('is_active', true)->get()->each(function (LeaveType $lt) use ($employee, $year, $proRation, &$created): void {
                $credits = round((float) $lt->default_balance * $proRation, 1);
                $created += DB::table('employee_leave_balances')->insertOrIgnore([
                    'employee_id' => $employee->id,
                    'leave_type_id' => $lt->id,
                    'year' => $year,
                    'total_credits' => $credits,
                    'used' => 0,
                    'remaining' => $credits,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        });

        return $created;
    }

    public function consume(int $employeeId, int $leaveTypeId, int $year, float $days): EmployeeLeaveBalance
    {
        return DB::transaction(function () use ($employeeId, $leaveTypeId, $year, $days) {
            /** @var EmployeeLeaveBalance $bal */
            $bal = EmployeeLeaveBalance::query()
                ->where('employee_id', $employeeId)
                ->where('leave_type_id', $leaveTypeId)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();
            if (! $bal) {
                throw new BusinessRuleException(
                    'Leave balance is not initialized for this employee, leave type, and year. Contact HR before approving this request.',
                );
            }
            if ($days > (float) $bal->remaining) {
                throw new InsufficientLeaveBalanceException(
                    "Insufficient leave balance ({$bal->remaining} remaining; {$days} requested)."
                );
            }
            $bal->used = (float) $bal->used + $days;
            $bal->remaining = (float) $bal->total_credits - (float) $bal->used;
            $bal->save();
            return $bal;
        });
    }

    public function restore(int $employeeId, int $leaveTypeId, int $year, float $days): void
    {
        DB::transaction(function () use ($employeeId, $leaveTypeId, $year, $days) {
            $bal = EmployeeLeaveBalance::query()
                ->where('employee_id', $employeeId)
                ->where('leave_type_id', $leaveTypeId)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();
            if (! $bal) {
                throw new BusinessRuleException(
                    'Leave balance is not initialized for this employee, leave type, and year. The request was not cancelled.',
                );
            }
            $bal->used = max(0, (float) $bal->used - $days);
            $bal->remaining = (float) $bal->total_credits - (float) $bal->used;
            $bal->save();
        });
    }
}
