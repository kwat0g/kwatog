<?php

declare(strict_types=1);

namespace App\Modules\Loans\Support;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Loans\Enums\LoanStatus;
use App\Modules\Loans\Models\EmployeeLoan;

/**
 * The only legal loan lifecycle transitions.
 *
 * Active cancellation is deliberately absent: an outstanding balance must
 * remain collectible until a separate, approved write-off/settlement path is
 * defined by the business owner.
 */
final class LoanStateMachine
{
    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        'pending' => ['active', 'rejected', 'cancelled'],
        'active' => ['paid'],
        // A controlled ledger reversal may reopen a paid loan.
        'paid' => ['active'],
        'rejected' => [],
        'cancelled' => [],
    ];

    public function transition(EmployeeLoan $loan, LoanStatus $target): void
    {
        $current = $loan->status instanceof LoanStatus
            ? $loan->status
            : LoanStatus::tryFrom((string) $loan->getRawOriginal('status'));

        if ($current === null) {
            throw new BusinessRuleException('Loan status is invalid; the lifecycle cannot continue.');
        }

        if ($current === $target) {
            return;
        }

        $allowed = self::TRANSITIONS[$current->value] ?? [];
        if (! in_array($target->value, $allowed, true)) {
            throw new BusinessRuleException(sprintf(
                'Loan cannot transition from %s to %s.',
                $current->label(),
                $target->label(),
            ));
        }

        $loan->status = $target;
    }
}
