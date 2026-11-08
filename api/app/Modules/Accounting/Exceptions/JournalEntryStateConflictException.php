<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A journal entry changed state before the requested action could be applied. */
final class JournalEntryStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'journal_entry_state_conflict';
    }
}
