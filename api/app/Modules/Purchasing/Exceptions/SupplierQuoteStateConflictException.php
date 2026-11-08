<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A supplier quotation action used RFQ or quote state that has since changed. */
final class SupplierQuoteStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'supplier_quote_state_conflict';
    }

    public function errorKey(): string
    {
        return 'state';
    }
}
