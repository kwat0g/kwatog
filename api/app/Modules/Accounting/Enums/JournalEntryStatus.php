<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Enums;

enum JournalEntryStatus: string
{
    case Draft    = 'draft';
    case Posted   = 'posted';
    case Reversed = 'reversed';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
