<?php

declare(strict_types=1);

namespace App\Modules\Quality\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A PPAP action was based on a status that has since changed. */
final class PpapStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'ppap_state_conflict';
    }

    public function errorKey(): string
    {
        return 'state';
    }
}
