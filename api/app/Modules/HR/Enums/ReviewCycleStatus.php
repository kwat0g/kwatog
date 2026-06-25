<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum ReviewCycleStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Closed = 'closed';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
