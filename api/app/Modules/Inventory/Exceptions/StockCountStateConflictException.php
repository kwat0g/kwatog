<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A concurrent stock-count transition made the submitted action stale. */
final class StockCountStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'stock_count_state_conflict';
    }

    public function errorKey(): string
    {
        return 'status';
    }
}
