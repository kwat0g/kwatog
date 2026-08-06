<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum PayrollPeriodType: string
{
    case Regular = 'false';
    case ThirteenthMonth = 'true';

    public function label(): string
    {
        return $this === self::ThirteenthMonth ? '13th Month' : 'Regular';
    }
}
