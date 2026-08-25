<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Support;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Models\ReturnRequest;

/** The single application-level lifecycle table for RMA status changes. */
final class ReturnRequestStateMachine
{
    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        ReturnRequestStatus::Draft->value => [
            ReturnRequestStatus::PendingApproval->value,
            ReturnRequestStatus::Cancelled->value,
        ],
        ReturnRequestStatus::PendingApproval->value => [
            ReturnRequestStatus::Approved->value,
            ReturnRequestStatus::Rejected->value,
            ReturnRequestStatus::Cancelled->value,
        ],
        ReturnRequestStatus::Approved->value => [ReturnRequestStatus::Received->value],
        ReturnRequestStatus::Received->value => [ReturnRequestStatus::Inspected->value],
        // A failed retry of an already-staged Quality handoff returns the RMA
        // to the received queue so the operator can recover it explicitly.
        ReturnRequestStatus::Inspected->value => [
            ReturnRequestStatus::Completed->value,
            ReturnRequestStatus::Received->value,
        ],
        ReturnRequestStatus::Completed->value => [],
        ReturnRequestStatus::Rejected->value => [],
        ReturnRequestStatus::Cancelled->value => [],
    ];

    public function transition(ReturnRequest $rma, ReturnRequestStatus $target): void
    {
        $current = $rma->status instanceof ReturnRequestStatus
            ? $rma->status
            : ReturnRequestStatus::tryFrom((string) $rma->getRawOriginal('status'));

        if ($current === null) {
            throw new BusinessRuleException('Return request status is invalid; the lifecycle cannot continue.');
        }

        if ($current === $target) {
            return;
        }

        if (! in_array($target->value, self::TRANSITIONS[$current->value] ?? [], true)) {
            throw new BusinessRuleException(sprintf(
                'Return request cannot transition from %s to %s.',
                $current->label(),
                $target->label(),
            ));
        }
    }
}
