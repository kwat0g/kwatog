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
    case WriteOffPending = 'write_off_pending';
    case WrittenOff = 'written_off';

    public function label(): string
    {
        return match ($this) {
            self::WriteOffPending => 'Write-off pending',
            self::WrittenOff => 'Written off',
            default => ucfirst($this->value),
        };
    }
}
