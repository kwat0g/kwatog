<?php

declare(strict_types=1);

namespace App\Modules\HR\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A profile update review was submitted after its review stage changed. */
final class ProfileUpdateRequestStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'profile_update_request_state_conflict';
    }

    public function errorKey(): string
    {
        return 'state';
    }
}
