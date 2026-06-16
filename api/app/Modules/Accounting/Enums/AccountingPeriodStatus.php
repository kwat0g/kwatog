<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Enums;

enum AccountingPeriodStatus: string
{
    case Open     = 'open';
    case Closed   = 'closed';
    case Reopened = 'reopened';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Open     => 'Open',
            self::Closed   => 'Closed',
            self::Reopened => 'Reopened',
        };
    }
}
