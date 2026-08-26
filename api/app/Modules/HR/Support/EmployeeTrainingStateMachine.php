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

        // BLOCKED ON A POLICY DECISION — do not "fix" this short-circuit in
        // isolation. It makes `completed -> completed` a silent no-op, so a
        // second recordCompletion() call rewrites completed_at, recomputes
        // expires_at and clears the alert bookkeeping on an already-signed-off
        // record. Two committed tests disagree about whether that is correct:
        //
        //   EmployeeTrainingExpiresAtTest::test_recompletion_resets_alert_state
        //     (2026-06-15, deliberate) REQUIRES it — re-completion is the retake
        //     / recertification path and must reset the alert marker.
        //   EmployeeTrainingAssignTest::test_training_lifecycle_rejects_
        //     recompletion_and_cancelling_completed_record (2026-08-26, from the
        //     unreviewed batch commit) REQUIRES the opposite, a 422.
        //
        // Removing these three lines makes the second test pass and the first
        // fail. Recertification policy is an HR/IATF question, so it is recorded
        // in audit/domains/people/employee-master/fix-log.md rather than guessed
        // at here. `completed -> cancelled` is already refused by the table.
        if ($current === $target) {
            return;
        }

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
