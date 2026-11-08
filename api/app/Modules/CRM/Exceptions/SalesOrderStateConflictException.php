<?php

declare(strict_types=1);

namespace App\Modules\CRM\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A Sales Order confirmation was based on a state that has since changed. */
final class SalesOrderStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'sales_order_state_conflict';
    }

    public function errorKey(): string
    {
        return 'state';
    }
}
