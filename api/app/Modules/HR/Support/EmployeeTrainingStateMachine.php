<?php

declare(strict_types=1);

namespace App\Modules\HR\Support;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\HR\Enums\EmployeeTrainingStatus;
use App\Modules\HR\Models\EmployeeTraining;

/** The single legal lifecycle transition table for employee training. */
final class EmployeeTrainingStateMachine
{
    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        EmployeeTrainingStatus::Scheduled->value => [
            EmployeeTrainingStatus::Completed->value,
            EmployeeTrainingStatus::Cancelled->value,
        ],
        EmployeeTrainingStatus::Completed->value => [
            EmployeeTrainingStatus::Expired->value,
        ],
        EmployeeTrainingStatus::Expired->value => [],
        EmployeeTrainingStatus::Cancelled->value => [],
    ];

    public function transition(EmployeeTraining $record, EmployeeTrainingStatus $target): void
    {
        $current = $record->status instanceof EmployeeTrainingStatus
            ? $record->status
            : EmployeeTrainingStatus::tryFrom((string) $record->getRawOriginal('status'));

        if ($current === null) {
            throw new BusinessRuleException('Employee training status is invalid; the lifecycle cannot continue.');
        }

        // Policy decision (2026-09-04, recertification — Option B in
        // audit/domains/people/employee-master/fix-log.md): a completed record
        // is immutable. Re-completing or cancelling it is refused (422) — a
        // retake / recertification creates a NEW assignment record, so the old
        // signed-off row keeps its completed_at / expires_at / certificate
        // history for IATF traceability. Self-transitions are therefore never
        // a silent no-op. `completed -> expired` remains the single legal
        // onward move, driven by the expiry engine.
        if (! in_array($target->value, self::TRANSITIONS[$current->value] ?? [], true)) {
            throw new BusinessRuleException(sprintf(
                'Employee training cannot transition from %s to %s.',
                $current->value,
                $target->value,
            ));
        }

        $record->status = $target;
    }
}
