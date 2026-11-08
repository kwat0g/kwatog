<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A period changed to a state that no longer permits the requested action. */
final class PayrollPeriodStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'payroll_period_state_conflict';
    }
}
