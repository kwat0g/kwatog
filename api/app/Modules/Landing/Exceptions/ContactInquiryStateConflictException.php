<?php

declare(strict_types=1);

namespace App\Modules\Landing\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

final class ContactInquiryStateConflictException extends BusinessRuleException
{
    public function errorCode(): ?string
    {
        return 'contact_inquiry_state_conflict';
    }
}
