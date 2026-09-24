<?php

declare(strict_types=1);

namespace App\Modules\HR\Support;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Employee;

/** The single lifecycle transition table for employee status changes. */
final class EmployeeStateMachine
{
    /** @var array<string, array<int, string>> */
    public const TRANSITIONS = [
        'active' => ['active', 'on_leave', 'suspended'],
        'on_leave' => ['on_leave', 'active', 'suspended'],
        'suspended' => ['suspended', 'active', 'on_leave'],
        'resigned' => [],
        'terminated' => [],
        'retired' => [],
    ];

    public function transition(Employee $employee, EmployeeStatus $to): void
    {
        $this->apply($employee, $to, false);
    }

    /** Only finalized clearance may enter a terminal employment state. */
    public function transitionAfterClearance(Employee $employee, EmployeeStatus $to): void
    {
        $this->apply($employee, $to, true);
    }

    private function apply(Employee $employee, EmployeeStatus $to, bool $allowTerminal): void
    {
        $from = $employee->status instanceof EmployeeStatus
            ? $employee->status
            : EmployeeStatus::from((string) $employee->getRawOriginal('status'));

        $allowed = self::TRANSITIONS[$from->value] ?? [];
        if ($allowTerminal
            && in_array($from, [EmployeeStatus::Active, EmployeeStatus::OnLeave, EmployeeStatus::Suspended], true)
            && in_array($to, [
            EmployeeStatus::Resigned,
            EmployeeStatus::Terminated,
            EmployeeStatus::Retired,
            ], true)) {
            $allowed[] = $to->value;
        }

        if (! in_array($to->value, $allowed, true)) {
            throw new BusinessRuleException(
                "Employee status cannot transition from [{$from->value}] to [{$to->value}].",
            );
        }

        if ($from !== $to) {
            $employee->forceFill(['status' => $to->value])->save();
        }
    }
}
