<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum SuccessionReadiness: string
{
    case ReadyNow = 'ready_now';
    case Ready1Year = 'ready_1_year';
    case Ready2Years = 'ready_2_years';
    case DevelopmentNeeded = 'development_needed';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
