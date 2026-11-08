<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

final class ShipmentStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'shipment_state_changed';
    }
}
