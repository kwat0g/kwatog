<?php

declare(strict_types=1);

namespace App\Modules\Forecasting\Enums;

enum DemandSource: string
{
    case Forecast = 'forecast';
    case Historical = 'historical';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Forecast => 'Forecast',
            self::Historical => 'Last 30d avg',
            self::None => 'No demand',
        };
    }
}
