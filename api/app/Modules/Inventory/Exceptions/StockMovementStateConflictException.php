<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

final class StockMovementStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'stock_movement_state_conflict';
    }
}
