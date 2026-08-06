<?php

declare(strict_types=1);

namespace App\Modules\HR\Enums;

/**
 * How an employee's basic pay is quoted.
 *
 * Both are FLAT per semi-monthly cutoff — the company pays on a semi-monthly
 * cycle regardless, so the only difference is which column carries the figure
 * and how it is scaled to the monthly basis government contributions need:
 *
 *   monthly       basic_monthly_salary ÷ 2 per cutoff, gov basis = the salary
 *   semi_monthly  semi_monthly_rate    per cutoff,     gov basis = rate × 2
 *
 * `daily` was retired by migration 0437 — see that file for why (its
 * days-worked basic pay disagreed with the monthly gov-contribution basis, so
 * any absence produced a zero-net / high-deduction anomaly that blocked
 * finalize).
 */
enum PayType: string
{
    case Monthly     = 'monthly';
    case SemiMonthly = 'semi_monthly';

    public function label(): string
    {
        return match ($this) {
            self::Monthly     => 'Monthly',
            self::SemiMonthly => 'Semi-monthly',
        };
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
