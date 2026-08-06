<?php

declare(strict_types=1);

namespace App\Modules\Loans\Enums;

enum LoanPaymentType: string
{
    case PayrollDeduction = 'payroll_deduction';
    case Manual           = 'manual';
    case FinalPay         = 'final_pay';

    public function label(): string
    {
        return match ($this) {
            self::PayrollDeduction => 'Payroll deduction',
            self::Manual => 'Manual payment',
            self::FinalPay => 'Final pay',
        };
    }
}
