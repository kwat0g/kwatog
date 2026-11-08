<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A Purchase Order action was based on a state that has since changed. */
final class PurchaseOrderStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'purchase_order_state_conflict';
    }

    public function errorKey(): string
    {
        return 'state';
    }
}
