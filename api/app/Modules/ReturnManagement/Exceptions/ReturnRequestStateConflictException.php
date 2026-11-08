<?php

declare(strict_types=1);

namespace App\Modules\ReturnManagement\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A Return Request action was based on state that changed before the write. */
final class ReturnRequestStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'return_request_state_conflict';
    }

    public function errorKey(): string
    {
        return 'state';
    }
}
