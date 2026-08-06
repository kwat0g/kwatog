<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Enums;

enum StatementAgingBucket: string
{
    case Current = 'current';
    case Days30 = 'd30_days';
    case Days60 = 'd60_days';
    case Days90Plus = 'd90_plus';

    public function label(): string
    {
        return match ($this) {
            self::Current => 'Current',
            self::Days30 => '1–30 Days',
            self::Days60 => '31–60 Days',
            self::Days90Plus => '61+ Days',
        };
    }
}
