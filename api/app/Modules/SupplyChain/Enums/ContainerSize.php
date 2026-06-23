<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Enums;

/** Container size standards for freight shipping. */
enum ContainerSize: string
{
    case TwentyFt    = '20ft';
    case FortyFt     = '40ft';
    case FortyFtHc   = '40ftHC';
    case FortyFiveFt = '45ft';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
