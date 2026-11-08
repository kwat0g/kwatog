<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** An MRB release was requested from a state that has already changed. */
final class MaterialReviewRecordStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'material_review_record_state_conflict';
    }

    public function errorKey(): string
    {
        return 'state';
    }
}
