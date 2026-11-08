<?php

declare(strict_types=1);

namespace App\Modules\Quality\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** The inspection changed after the client read the result version it submits. */
final class InspectionStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'inspection_state_conflict';
    }

    public function errorKey(): string
    {
        return 'expected_result_version';
    }
}
