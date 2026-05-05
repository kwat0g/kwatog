<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum PayrollAnomalyType: string
{
    case LargeChange    = 'large_change';
    case ExcessiveOt    = 'excessive_ot';
    case HighDeduction  = 'high_deduction';
    case FirstPayroll   = 'first_payroll';
    case ZeroPay        = 'zero_pay';

    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::LargeChange   => 'Large net pay change',
            self::ExcessiveOt   => 'Excessive overtime',
            self::HighDeduction => 'High deduction ratio',
            self::FirstPayroll  => 'First payroll — verify',
            self::ZeroPay       => 'Zero net pay',
        };
    }
}
