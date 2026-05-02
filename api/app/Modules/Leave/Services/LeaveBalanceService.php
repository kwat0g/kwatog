<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Modules\Leave\Models\EmployeeLeaveBalance;
use App\Modules\Leave\Models\LeaveType;
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

    public function consume(int $employeeId, int $leaveTypeId, int $year, float $days): EmployeeLeaveBalance
    {
        return DB::transaction(function () use ($employeeId, $leaveTypeId, $year, $days) {
            /** @var EmployeeLeaveBalance $bal */
            $bal = EmployeeLeaveBalance::query()
                ->where('employee_id', $employeeId)
                ->where('leave_type_id', $leaveTypeId)
                ->where('year', $year)
                ->lockForUpdate()
                ->firstOrFail();
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
            if (! $bal) return;
            $bal->used = max(0, (float) $bal->used - $days);
            $bal->remaining = (float) $bal->total_credits - (float) $bal->used;
            $bal->save();
        });
    }
}
