<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Enums;

enum JournalEntryStatus: string
{
    case Draft    = 'draft';
    case Posted   = 'posted';
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Posted => 'Posted',
            self::Reversed => 'Reversed',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
