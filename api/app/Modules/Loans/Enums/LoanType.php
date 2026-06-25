<?php

declare(strict_types=1);

namespace App\Modules\Loans\Enums;

enum LoanType: string
{
    case CompanyLoan  = 'company_loan';
    case CashAdvance  = 'cash_advance';
    case SssLoan      = 'sss_loan';
    case PagibigLoan  = 'pagibig_loan';

    public function label(): string
    {
        return match ($this) {
            self::CompanyLoan => 'Company Loan',
            self::CashAdvance => 'Cash Advance',
            self::SssLoan => 'SSS Salary Loan',
            self::PagibigLoan => 'Pag-IBIG Multi-Purpose Loan',
        };
    }

    public function isGovernment(): bool
    {
        return in_array($this, [self::SssLoan, self::PagibigLoan]);
    }

    public function defaultInterestRate(): string
    {
        return match ($this) {
            self::SssLoan => '0.10',
            self::PagibigLoan => '0.105',
            default => '0.00',
        };
    }

    /** Workflow type for ApprovalService. */
    public function workflowType(): string
    {
        return $this->value;
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
