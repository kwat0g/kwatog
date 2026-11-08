<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

final class VehicleStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'vehicle_state_changed';
    }
}
