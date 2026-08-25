<?php

declare(strict_types=1);

namespace App\Modules\Quality\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

final class InspectionCertificateException extends BusinessRuleException
{
    public function __construct(
        string $message,
        private readonly string $errorIdentifier,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): ?string
    {
        return $this->errorIdentifier;
    }
}
