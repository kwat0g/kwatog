<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum SuccessionStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
