<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Enums;

enum BillPaymentStatus: string
{
    case Posted = 'posted';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Posted => 'Posted',
            self::Voided => 'Voided',
        };
    }
}
