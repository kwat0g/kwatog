<?php

declare(strict_types=1);

namespace App\Modules\HR\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A stage action was submitted against an older application state. */
final class RecruitmentStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'recruitment_state_conflict';
    }

    public function errorKey(): string
    {
        return 'state';
    }
}
