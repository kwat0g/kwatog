<?php

declare(strict_types=1);

namespace App\Modules\Assets\Enums;

enum AssetCategory: string
{
    case Machine   = 'machine';
    case Mold      = 'mold';
    case Vehicle   = 'vehicle';
    case Equipment = 'equipment';
    case Furniture = 'furniture';
    case Other     = 'other';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
