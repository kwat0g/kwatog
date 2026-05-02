<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum PayType: string
{
    case Monthly = 'monthly';
    case Daily   = 'daily';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Daily   => 'Daily',
        };
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
