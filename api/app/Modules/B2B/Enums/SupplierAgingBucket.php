<?php

declare(strict_types=1);

namespace App\Modules\B2B\Enums;

enum SupplierAgingBucket: string
{
    case Current = 'current';
    case Days1To30 = 'd1_30';
    case Days31To60 = 'd31_60';
    case Days61To90 = 'd61_90';
    case Days91Plus = 'd91_plus';

    public function label(): string
    {
        return match ($this) {
            self::Current => 'Current (Not Due)',
            self::Days1To30 => '1–30 Days',
            self::Days31To60 => '31–60 Days',
            self::Days61To90 => '61–90 Days',
            self::Days91Plus => '91+ Days',
        };
    }
}
