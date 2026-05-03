<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

/** Sprint 7 — Task 61. NCR lifecycle. */
enum NcrStatus: string
{
    case Open       = 'open';
    case InProgress = 'in_progress';
    case Closed     = 'closed';
    case Cancelled  = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Closed, self::Cancelled], true);
    }

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
