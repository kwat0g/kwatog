<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum PayrollAdjustmentType: string
{
    case Underpayment = 'underpayment'; // employee was underpaid → refund (positive net effect)
    case Overpayment  = 'overpayment';  // employee was overpaid → recover (negative net effect)

    public function label(): string
    {
        return match ($this) {
            self::Underpayment => 'Underpayment Refund',
            self::Overpayment  => 'Overpayment Recovery',
        };
    }

    /**
     * Net-pay sign multiplier: +1 adds to net, -1 subtracts.
     */
    public function signMultiplier(): string
    {
        return match ($this) {
            self::Underpayment => '1',
            self::Overpayment  => '-1',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
