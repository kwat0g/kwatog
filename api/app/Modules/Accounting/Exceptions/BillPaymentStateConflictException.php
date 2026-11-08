<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A bill-payment action was based on a lifecycle state that has changed. */
final class BillPaymentStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'bill_payment_state_conflict';
    }

    public function errorKey(): string
    {
        return 'state';
    }
}
