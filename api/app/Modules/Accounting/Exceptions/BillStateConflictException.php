<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A bill action was based on a state that has since changed. */
final class BillStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'bill_state_conflict';
    }

    public function errorKey(): string
    {
        return 'state';
    }
}
