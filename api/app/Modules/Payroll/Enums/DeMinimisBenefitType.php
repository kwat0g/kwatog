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
