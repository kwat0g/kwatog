<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollPeriod;
use Carbon\CarbonImmutable;

/**
 * The attendance-side write fence for payroll periods.
 *
 * Call this inside the same DB transaction as the attendance mutation. The
 * locked period rows serialize the check with PayrollPeriodService's lifecycle
 * transitions, so a period cannot become finalized between the check and save.
 */
class AttendanceDateMutabilityGuard
{
    public function assertMutable(int $employeeId, string $date): void
    {
        $normalizedDate = CarbonImmutable::parse($date)->toDateString();
        $employee = Employee::query()->findOrFail($employeeId);

        // Lock every overlapping period, not only periods already marked
        // locked. PayrollPeriodService takes this same row lock when it moves a
        // period to Finalized/Disbursed/Voided, so an import cannot pass the
        // check and then write after a concurrent lifecycle transition.
        $periods = PayrollPeriod::query()
            ->where('period_start', '<=', $normalizedDate)
            ->where('period_end', '>=', $normalizedDate)
            ->lockForUpdate()
            ->orderBy('id')
            ->get();

        foreach ($periods as $period) {
            if (! $this->periodAppliesToEmployee($period, $employee)) {
                continue;
            }
            if (! in_array($period->status?->value, [
                PayrollPeriodStatus::Finalized->value,
                PayrollPeriodStatus::Disbursed->value,
                PayrollPeriodStatus::Voided->value,
            ], true)) {
                continue;
            }

            $status = $period->status?->label() ?? 'locked';
            throw new BusinessRuleException(sprintf(
                'Attendance for %s is locked by payroll period %s–%s (%s). Void or correct the payroll period before changing this record.',
                $normalizedDate,
                CarbonImmutable::parse($period->period_start)->toDateString(),
                CarbonImmutable::parse($period->period_end)->toDateString(),
                strtolower($status),
            ));
        }
    }

    private function periodAppliesToEmployee(PayrollPeriod $period, Employee $employee): bool
    {
        $departmentIds = array_map('intval', (array) $period->scope_department_ids);
        if ($departmentIds !== [] && ! in_array((int) $employee->department_id, $departmentIds, true)) {
            return false;
        }

        $employmentTypes = array_map('strval', (array) $period->scope_employment_types);
        $employeeEmploymentType = $employee->employment_type instanceof \BackedEnum
            ? $employee->employment_type->value
            : $employee->employment_type;
        if ($employmentTypes !== []
            && ! in_array((string) $employeeEmploymentType, $employmentTypes, true)) {
            return false;
        }

        $payTypes = array_map('strval', (array) $period->scope_pay_types);
        $employeePayType = $employee->pay_type instanceof \BackedEnum
            ? $employee->pay_type->value
            : $employee->pay_type;
        if ($payTypes !== []
            && ! in_array((string) $employeePayType, $payTypes, true)) {
            return false;
        }

        return true;
    }
}
