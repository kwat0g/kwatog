<?php

declare(strict_types=1);

namespace App\Modules\Loans\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A concurrent decision changed the loan state before this action committed. */
final class LoanStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'loan_state_conflict';
    }
}
