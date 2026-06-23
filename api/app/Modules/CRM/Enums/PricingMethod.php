<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

enum PricingMethod: string
{
    case Flat   = 'flat';
    case Tiered = 'tiered';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Flat   => 'Flat',
            self::Tiered => 'Tiered',
        };
    }

    public static function default(): self
    {
        return self::Flat;
    }
}
