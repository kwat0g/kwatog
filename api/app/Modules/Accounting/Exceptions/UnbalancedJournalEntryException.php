<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Exceptions;

use RuntimeException;

/**
 * The debit and credit totals of a journal entry do not agree.
 *
 * Stays a bare RuntimeException — no BusinessRuleException parent, no render arm
 * — because who raised it decides what it means, and the two answers are
 * incompatible:
 *
 *   - from the JE form, it is user input, and JournalEntryController::store /
 *     update already answer 422 with `errors.lines` so the message lands on the
 *     line editor that caused it. `post()` names it too. Those three arms are
 *     the entire HTTP surface.
 *   - from an internal poster — MovementGlPostingService, PayrollGlPostingService,
 *     GrnGlPostingService, AssetService, DepreciationService, FinalPayService,
 *     InvoiceService, BillService, CreditNoteService — the lines were built by
 *     our own code from our own mapping. An imbalance there is a bug, and the
 *     honest answer is a 500 plus a stack trace, not a 422 telling an operator to
 *     correct a form they never filled in.
 *
 * Reparenting would also hand it to the ~20 `catch (BusinessRuleException)` arms
 * that degrade a failed GL handoff to manual_required. A poster that computes
 * unbalanced lines would then be quietly marked "needs manual attention" forever
 * instead of failing loudly on the first attempt.
 */
class UnbalancedJournalEntryException extends RuntimeException
{
    public function __construct(
        public readonly string $totalDebit,
        public readonly string $totalCredit,
    ) {
        parent::__construct(sprintf(
            'Journal entry is not balanced: debits=%s credits=%s (difference=%s)',
            $totalDebit,
            $totalCredit,
            \App\Common\Support\Money::sub($totalDebit, $totalCredit),
        ));
    }
}
