<?php

declare(strict_types=1);

namespace App\Modules\Assets\Enums;

enum DepreciationMethod: string
{
    case StraightLine     = 'straight_line';
    case DecliningBalance = 'declining_balance';

    public function label(): string
    {
        return match ($this) {
            self::StraightLine     => 'Straight Line',
            self::DecliningBalance => 'Declining Balance (200%)',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
