<?php

declare(strict_types=1);

namespace App\Modules\Loans\Enums;

enum LoanPaymentType: string
{
    case PayrollDeduction = 'payroll_deduction';
    case Manual           = 'manual';
    case FinalPay         = 'final_pay';
}
