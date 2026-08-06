<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

enum TransferOrderStatus: string
{
    case Pending     = 'pending';
    case Transferred = 'transferred';
    case Cancelled   = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending     => 'Pending',
            self::Transferred => 'Transferred',
            self::Cancelled   => 'Cancelled',
        };
    }
}
