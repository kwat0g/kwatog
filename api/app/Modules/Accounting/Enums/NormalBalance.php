<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Enums;

enum NormalBalance: string
{
    case Debit  = 'debit';
    case Credit = 'credit';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
