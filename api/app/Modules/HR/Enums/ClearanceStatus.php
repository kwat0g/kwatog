<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum ClearanceStatus: string
{
    case Pending     = 'pending';
    case InProgress  = 'in_progress';
    case Completed   = 'completed';
    case Finalized   = 'finalized';
    case Cancelled   = 'cancelled';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Finalized, self::Cancelled], true);
    }
}
