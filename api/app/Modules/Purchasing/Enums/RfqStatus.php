<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Enums;

enum RfqStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
    case Awarded = 'awarded';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Open => 'Open',
            self::Closed => 'Closed',
            self::Awarded => 'Awarded',
            self::Cancelled => 'Cancelled',
        };
    }

    /** A PR may have at most one RFQ in these states. */
    public static function active(): array
    {
        return [self::Draft->value, self::Open->value, self::Closed->value];
    }
}
