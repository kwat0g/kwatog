<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Enums;

enum MaterialIssueStatus: string
{
    case Draft     = 'draft';
    case Issued    = 'issued';
    case Cancelled = 'cancelled';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
