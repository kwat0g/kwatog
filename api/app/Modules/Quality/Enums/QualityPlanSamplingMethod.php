<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

enum QualityPlanSamplingMethod: string
{
    case Aql = 'aql';
    case Fixed = 'fixed';
    case Full = 'full';

    public function label(): string
    {
        return match ($this) {
            self::Aql => 'AQL General II',
            self::Fixed => 'Fixed sample',
            self::Full => '100% inspection',
        };
    }
}
