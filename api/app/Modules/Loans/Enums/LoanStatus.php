<?php

declare(strict_types=1);

namespace App\Modules\Loans\Enums;

enum LoanStatus: string
{
    case Pending   = 'pending';
    case Active    = 'active';
    case Paid      = 'paid';
    case Cancelled = 'cancelled';
    case Rejected  = 'rejected';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
