<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Enums;

enum PurchaseRequestStatus: string
{
    case Draft     = 'draft';
    case Pending   = 'pending';
    case Approved  = 'approved';
    case Rejected  = 'rejected';
    case Converted = 'converted';
    case Cancelled = 'cancelled';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
