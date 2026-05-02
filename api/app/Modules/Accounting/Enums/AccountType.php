<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Enums;

enum AccountType: string
{
    case Asset     = 'asset';
    case Liability = 'liability';
    case Equity    = 'equity';
    case Revenue   = 'revenue';
    case Expense   = 'expense';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Asset     => 'Asset',
            self::Liability => 'Liability',
            self::Equity    => 'Equity',
            self::Revenue   => 'Revenue',
            self::Expense   => 'Expense',
        };
    }

    /**
     * Default normal balance side for a given account type — used as a hint
     * when creating a new account and as a sanity check on save.
     */
    public function defaultNormalBalance(): NormalBalance
    {
        return match ($this) {
            self::Asset, self::Expense                      => NormalBalance::Debit,
            self::Liability, self::Equity, self::Revenue    => NormalBalance::Credit,
        };
    }
}
