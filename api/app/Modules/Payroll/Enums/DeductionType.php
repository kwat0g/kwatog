<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum DeductionType: string
{
    case Sss            = 'sss';
    case Philhealth     = 'philhealth';
    case Pagibig        = 'pagibig';
    case WithholdingTax = 'withholding_tax';
    case Loan           = 'loan';
    case CashAdvance    = 'cash_advance';
    case Adjustment     = 'adjustment';
    case ThirteenthMonth = 'thirteenth_month';
    case Other          = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Sss            => 'SSS',
            self::Philhealth     => 'PhilHealth',
            self::Pagibig        => 'Pag-IBIG',
            self::WithholdingTax => 'Withholding Tax',
            self::Loan           => 'Loan',
            self::CashAdvance    => 'Cash Advance',
            self::Adjustment     => 'Adjustment',
            self::ThirteenthMonth => '13th Month',
            self::Other          => 'Other',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
