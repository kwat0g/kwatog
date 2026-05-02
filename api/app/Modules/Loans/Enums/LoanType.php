<?php

declare(strict_types=1);

namespace App\Modules\Loans\Enums;

enum LoanType: string
{
    case CompanyLoan  = 'company_loan';
    case CashAdvance  = 'cash_advance';

    public function label(): string
    {
        return match ($this) {
            self::CompanyLoan => 'Company Loan',
            self::CashAdvance => 'Cash Advance',
        };
    }

    /** Workflow type for ApprovalService. */
    public function workflowType(): string
    {
        return $this->value; // matches WorkflowSeeder keys
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
