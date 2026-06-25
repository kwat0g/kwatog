<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum SuccessionPriority: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
