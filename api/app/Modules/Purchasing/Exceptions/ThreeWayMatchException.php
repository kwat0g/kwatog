<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Exceptions;

class ThreeWayMatchException extends \RuntimeException
{
    public function __construct(string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
