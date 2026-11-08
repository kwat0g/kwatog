<?php

declare(strict_types=1);

namespace App\Modules\Quality\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A close/cancel command was submitted after the NCR reached a terminal state. */
final class NcrStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'ncr_state_conflict';
    }

    public function errorKey(): string
    {
        return 'state';
    }
}
