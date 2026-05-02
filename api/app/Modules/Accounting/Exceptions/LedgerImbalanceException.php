<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Exceptions;

use RuntimeException;

/**
 * Thrown when a financial-statement service detects that posted JE lines do
 * not net to zero across the trial balance — indicates a serious bug.
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
