<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A competing action changed an overtime request before this action committed. */
final class OvertimeStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'overtime_state_conflict';
    }
}
