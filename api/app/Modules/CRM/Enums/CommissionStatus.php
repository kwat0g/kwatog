<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

enum CommissionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Paid = 'paid';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
