<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

enum ReviewCycleType: string
{
    case Annual = 'annual';
    case SemiAnnual = 'semi_annual';
    case Quarterly = 'quarterly';
    case Probationary = 'probationary';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
