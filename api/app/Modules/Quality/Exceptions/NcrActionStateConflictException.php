<?php

declare(strict_types=1);

namespace App\Modules\Quality\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A CAPA verdict was submitted against an action whose state has changed. */
final class NcrActionStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'ncr_action_state_conflict';
    }

    public function errorKey(): string
    {
        return 'state';
    }
}
