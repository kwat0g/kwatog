<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Exceptions;

use App\Common\Exceptions\BusinessRuleException;

/** A punch the rules refuse. Rendered as 422 with `code` by bootstrap/app.php. */
class PunchRejectedException extends BusinessRuleException
{
    public function __construct(private readonly string $code_, string $message)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->code_;
    }
}
