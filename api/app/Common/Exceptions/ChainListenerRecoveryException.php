<?php

declare(strict_types=1);

namespace App\Common\Exceptions;

use RuntimeException;
use Throwable;

class ChainListenerRecoveryException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 409, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
