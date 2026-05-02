<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Enums;

enum PurchaseRequestPriority: string
{
    case Normal   = 'normal';
    case Urgent   = 'urgent';
    case Critical = 'critical';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
