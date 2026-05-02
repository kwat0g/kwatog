<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services\Government;

use App\Modules\Payroll\Enums\ContributionAgency;
use App\Modules\Payroll\Services\GovernmentContributionTableService;

/**
 * PhilHealth premium computation.
 *
 * Stored as a single row with rate-based ee_amount and er_amount.
 *   basis = clamp(salary, bracket_min, bracket_max)
 *   ee    = basis × ee_amount
 *   er    = basis × er_amount
 *
 * 2024 rate: 5% combined → 2.5% each split. (Stored as 0.0225 / 0.0225 in this
 * codebase to match Sprint 3 plan; project decision is to honor whatever the
 * admin enters, not hard-code the rate.)
 */
class PhilhealthComputationService
{
    public function __construct(private readonly GovernmentContributionTableService $tables) {}

    /**
     * @param  string|float|int  $monthlySalary
     * @return array{ee: string, er: string}
     */
    public function compute(string|float|int $monthlySalary): array
    {
        $salary = (string) $monthlySalary;
        if (bccomp($salary, '0', 2) <= 0) {
            return ['ee' => '0.00', 'er' => '0.00'];
        }

        $row = $this->tables->activeBrackets(ContributionAgency::Philhealth)->first();
        if (! $row) {
            return ['ee' => '0.00', 'er' => '0.00'];
        }

        $floor   = (string) $row->bracket_min;
        $ceiling = (string) $row->bracket_max;

        $basis = $salary;
        if (bccomp($basis, $floor, 2) < 0)   $basis = $floor;
        if (bccomp($basis, $ceiling, 2) > 0) $basis = $ceiling;

        $ee = bcmul($basis, (string) $row->ee_amount, 4);
        $er = bcmul($basis, (string) $row->er_amount, 4);

        // Round to 2 decimals (banker's rounding via bcadd '0' at scale 2).
        return [
            'ee' => self::round2($ee),
            'er' => self::round2($er),
        ];
    }

    private static function round2(string $v): string
    {
        // bcadd at scale 2 truncates; we want HALF_UP rounding.
        // Trick: add 0.005 then bcadd at scale 2.
        $sign = bccomp($v, '0', 4) >= 0 ? '1' : '-1';
        $abs = ltrim($v, '-');
        $rounded = bcadd($abs, '0.005', 2);
        return $sign === '-1' ? bcmul($rounded, '-1', 2) : bcadd($rounded, '0', 2);
    }
}
