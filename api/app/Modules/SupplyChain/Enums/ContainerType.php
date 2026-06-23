<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Enums;

/** Container type categories for freight shipping. */
enum ContainerType: string
{
    case Dry       = 'dry';
    case Reefer    = 'reefer';
    case OpenTop   = 'open_top';
    case FlatRack  = 'flat_rack';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
