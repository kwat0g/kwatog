<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

/**
 * Statutory de minimis benefit types under Philippine tax law (BIR RMC 2024 rules).
 *
 * Each case carries a monthly limit. Annual-type benefits (uniform, award, gifts)
 * are pro-rated per payroll period and tracked year-to-date against the annual cap.
 * The excess above the limit is taxable compensation.
 */
enum DeMinimisBenefitType: string
{
    case RiceSubsidy                = 'rice_subsidy';
    case UniformAllowance           = 'uniform_allowance';
    case MedicalCashAllowance       = 'medical_cash_allowance';
    case LaundryAllowance           = 'laundry_allowance';
    case EmployeeAchievementAward   = 'employee_achievement_award';
    case Gifts                      = 'gifts';
    case MealAllowancePerOt         = 'meal_allowance_per_ot';

    public function label(): string
    {
        return match ($this) {
            self::RiceSubsidy              => 'Rice Subsidy',
            self::UniformAllowance         => 'Uniform Allowance',
            self::MedicalCashAllowance     => 'Medical Cash Allowance',
            self::LaundryAllowance         => 'Laundry Allowance',
            self::EmployeeAchievementAward => 'Employee Achievement Award',
            self::Gifts                    => 'Gifts (Christmas/Birthday)',
            self::MealAllowancePerOt       => 'Meal Allowance per OT',
        };
    }

    /**
     * Statutory monthly limit in pesos as a decimal string.
     *
     * Annual-type benefits return the monthly pro-rated equivalent
     * (annual limit / 12) for per-period comparison.
     */
    public function monthlyLimit(): string
    {
        return match ($this) {
            self::RiceSubsidy              => '2000.00',
            self::UniformAllowance         => '500.00',   // 6,000/yr / 12
            self::MedicalCashAllowance     => '1500.00',
            self::LaundryAllowance         => '300.00',
            self::EmployeeAchievementAward => '833.33',   // 10,000/yr / 12
            self::Gifts                    => '416.67',   // 5,000/yr / 12
            self::MealAllowancePerOt       => '0.00',     // Flag-only; computed per OT day
        };
    }

    /**
     * Annual cap — meaningful only for annual-type benefits.
     * Returns null for strictly monthly benefits.
     */
    public function annualLimit(): ?string
    {
        return match ($this) {
            self::UniformAllowance         => '6000.00',
            self::EmployeeAchievementAward => '10000.00',
            self::Gifts                    => '5000.00',
            default                        => null,
        };
    }

    /**
     * Whether the benefit is tracked on an annual (year-to-date) basis.
     */
    public function isAnnual(): bool
    {
        return $this->annualLimit() !== null;
    }

    /**
     * Whether this benefit type is a non-cash or flag-only type
     * that uses a different computation (e.g., meal_allowance_per_ot).
     */
    public function isFlagOnly(): bool
    {
        return $this === self::MealAllowancePerOt;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
