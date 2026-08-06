<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Enums;

enum LandedCostAllocationMethod: string
{
    case ByValue = 'by_value';
    case ByWeight = 'by_weight';
    case ByQuantity = 'by_quantity';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::ByValue => 'By value',
            self::ByWeight => 'By weight',
            self::ByQuantity => 'By quantity',
            self::Manual => 'Manual',
        };
    }

    public static function values(): array
    {
        return array_map(static fn (self $method): string => $method->value, self::cases());
    }
}
