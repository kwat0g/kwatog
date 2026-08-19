<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Exceptions;

use RuntimeException;

/**
 * Thrown when a financial-statement service detects that posted JE lines do
 * not net to zero across the trial balance — indicates a serious bug.
 *
 * Stays a bare RuntimeException on purpose, and the docblock line above is the
 * whole reason: nothing the reader of a trial balance can do will fix it. Every
 * JE that reaches the ledger passed the balance check in JournalEntryService, so
 * a non-zero trial balance means posted rows have been mutated, a migration has
 * dropped or duplicated lines, or the aggregation is wrong. A 500 with a stack
 * trace is the correct answer to that.
 *
 * A 422 would be actively worse than unhelpful: bootstrap/app.php refuses to map
 * RuntimeException globally precisely so that real faults cannot be dressed as
 * validation errors, and this is the clearest instance of the class it is
 * protecting. TrialBalanceService is also the only thrower and no caller catches
 * it, so there is no arm whose behaviour a reparent would preserve — it would
 * only relabel a bug.
 */
class LedgerImbalanceException extends RuntimeException
{
    public function __construct(string $totalDebit, string $totalCredit)
    {
        parent::__construct(sprintf(
            'Ledger imbalance detected: total debit=%s, total credit=%s',
            $totalDebit, $totalCredit,
        ));
    }
}
