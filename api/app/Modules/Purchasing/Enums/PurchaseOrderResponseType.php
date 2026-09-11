<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Enums;

/**
 * The kind of reply a supplier files against a sent purchase order.
 */
enum PurchaseOrderResponseType: string
{
    case Accept   = 'accept';
    case Propose  = 'propose';
    case Decline  = 'decline';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Accept   => 'Accepted as ordered',
            self::Propose  => 'Proposed changes',
            self::Decline  => 'Declined',
        };
    }
}
