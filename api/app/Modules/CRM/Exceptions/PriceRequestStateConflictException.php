<?php

declare(strict_types=1);

namespace App\Modules\CRM\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

final class PriceRequestStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'price_request_state_conflict';
    }
}
