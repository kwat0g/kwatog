<?php

declare(strict_types=1);

namespace App\Modules\Production\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

final class IllegalLifecycleTransitionException extends BusinessRuleException
{
    public function __construct(string $from, string $to)
    {
        parent::__construct("Illegal work-order lifecycle transition: {$from} → {$to}.");
    }

    public function errorCode(): ?string
    {
        return 'ILLEGAL_WORK_ORDER_TRANSITION';
    }
}
