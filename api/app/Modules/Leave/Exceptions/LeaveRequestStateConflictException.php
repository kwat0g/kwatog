<?php

declare(strict_types=1);

namespace App\Modules\Leave\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A leave action targeted a workflow state that changed before it was handled. */
final class LeaveRequestStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'leave_request_state_conflict';
    }
}
