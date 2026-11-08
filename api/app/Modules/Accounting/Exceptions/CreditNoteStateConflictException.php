<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A credit-note action was based on lifecycle state that has since changed. */
final class CreditNoteStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'credit_note_state_conflict';
    }

    public function errorKey(): string
    {
        return 'state';
    }
}
