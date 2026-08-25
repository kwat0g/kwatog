<?php

declare(strict_types=1);

namespace App\Modules\Quality\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

final class CapabilityStudyException extends BusinessRuleException
{
    public function __construct(
        string $message,
        private readonly string $errorIdentifier,
        private readonly string $field = 'spec_item_id',
    ) {
        parent::__construct($message);
    }

    public function errorCode(): ?string
    {
        return $this->errorIdentifier;
    }

    public function errorKey(): string
    {
        return $this->field;
    }
}
