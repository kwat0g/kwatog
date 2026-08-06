<?php

declare(strict_types=1);

namespace App\Modules\Quality\Enums;

enum SpcChartType: string
{
    case XbarR = 'xbar_r';
    case Imr = 'imr';
    case PChart = 'p_chart';

    public function label(): string
    {
        return match ($this) {
            self::XbarR => 'X-bar / R',
            self::Imr => 'I-MR',
            self::PChart => 'p-chart',
        };
    }
}
