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
        // Terminal transitions remain valid here for legacy clearances that
        // were created before the initiation endpoint moved to this service;
        // current UI flows still require the clearance/final-pay guards first.
        'active' => ['active', 'on_leave', 'suspended', 'resigned', 'terminated', 'retired'],
        'on_leave' => ['on_leave', 'active', 'suspended', 'resigned', 'terminated', 'retired'],
        'suspended' => ['suspended', 'active', 'on_leave', 'resigned', 'terminated', 'retired'],
        'resigned' => [],
        'terminated' => [],
        'retired' => [],
    ];

    public function transition(Employee $employee, EmployeeStatus $to): void
    {
        $from = $employee->status instanceof EmployeeStatus
            ? $employee->status
            : EmployeeStatus::from((string) $employee->getRawOriginal('status'));

        if (! in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true)) {
            throw new BusinessRuleException(
                "Employee status cannot transition from [{$from->value}] to [{$to->value}].",
            );
        }

        if ($from !== $to) {
            $employee->forceFill(['status' => $to->value])->save();
        }
    }
}
