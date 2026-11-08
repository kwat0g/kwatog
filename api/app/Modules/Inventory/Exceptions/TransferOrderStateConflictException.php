<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A concurrent transfer-order transition made the submitted action stale. */
final class TransferOrderStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'transfer_order_state_conflict';
    }

    public function errorKey(): string
    {
        return 'status';
    }
}
