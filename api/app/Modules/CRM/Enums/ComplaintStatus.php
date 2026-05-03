<?php

declare(strict_types=1);

namespace App\Modules\CRM\Enums;

/** Sprint 7 — Task 68. Customer complaint lifecycle. */
enum ComplaintStatus: string
{
    case Open          = 'open';
    case Investigating = 'investigating';
    case Resolved      = 'resolved';
    case Closed        = 'closed';
    case Cancelled     = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Closed, self::Cancelled], true);
    }

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
