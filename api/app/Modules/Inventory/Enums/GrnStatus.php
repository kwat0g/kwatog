<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

enum GrnStatus: string
{
    case PendingQc       = 'pending_qc';
    case Accepted        = 'accepted';
    case PartialAccepted = 'partial_accepted';
    case Rejected        = 'rejected';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
