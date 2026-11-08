<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A Purchase Request action was based on a state that has since changed. */
final class PurchaseRequestStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'purchase_request_state_conflict';
    }

    public function errorKey(): string
    {
        return 'state';
    }
}
