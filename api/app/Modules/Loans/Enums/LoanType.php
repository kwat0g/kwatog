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

    public function isSupported(): bool
    {
        return ! $this->isGovernment();
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

    /** @return array<int, string> */
    public static function supportedValues(): array
    {
        return array_map(
            static fn (self $type): string => $type->value,
            array_filter(self::cases(), static fn (self $type): bool => $type->isSupported()),
        );
    }
}
