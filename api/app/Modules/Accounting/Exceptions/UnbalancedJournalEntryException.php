<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Exceptions;

use RuntimeException;

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
