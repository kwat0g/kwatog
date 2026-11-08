<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A buyer RFQ action was based on a lifecycle state that has since changed. */
final class RequestForQuoteStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'request_for_quote_state_conflict';
    }

    public function errorKey(): string
    {
        return 'state';
    }
}
