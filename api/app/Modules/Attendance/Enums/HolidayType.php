<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Enums;

enum HolidayType: string
{
    case Regular           = 'regular';
    case SpecialNonWorking = 'special_non_working';

    public function label(): string
    {
        return match ($this) {
            self::Regular           => 'Regular holiday',
            self::SpecialNonWorking => 'Special non-working',
        };
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
