<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A competing action made a previously available stock-adjustment action stale. */
final class StockAdjustmentStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'stock_adjustment_state_conflict';
    }

    public function errorKey(): string
    {
        return 'status';
    }
}
