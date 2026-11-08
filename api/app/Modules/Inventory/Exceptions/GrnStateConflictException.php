<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A competing GRN transition made a previously available action stale. */
final class GrnStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'grn_state_conflict';
    }

    public function errorKey(): string
    {
        return 'state';
    }
}
