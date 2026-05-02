<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services\Government;

use App\Modules\Payroll\Enums\ContributionAgency;
use App\Modules\Payroll\Services\GovernmentContributionTableService;

/**
 * BIR withholding tax (TRAIN Law) computation.
 *
 * Bracket convention: ee_amount = fixed_tax (peso), er_amount = rate_on_excess.
 *
 *   tax = fixed_tax + rate_on_excess × (taxable - bracket_min)
 *
 * Period type defaults to 'semi_monthly' to match company payroll cadence.
 * If a different period table is later seeded (monthly / weekly), the engine
 * will need a period filter on the query — currently all bir rows are SM.
 */
class BirTaxComputationService
{
    public function __construct(private readonly GovernmentContributionTableService $tables) {}

    /**
     * @return string  Tax in pesos, 2 decimals.
     */
    public function compute(string|float|int $taxablePay, string $periodType = 'semi_monthly'): string
    {
        $taxable = (string) $taxablePay;
        if (bccomp($taxable, '0', 2) <= 0) {
            return '0.00';
        }

        $brackets = $this->tables->activeBrackets(ContributionAgency::Bir);
        foreach ($brackets as $row) {
            $min = (string) $row->bracket_min;
            $max = (string) $row->bracket_max;
            if (bccomp($taxable, $min, 2) >= 0 && bccomp($taxable, $max, 2) <= 0) {
                $fixed = (string) $row->ee_amount;
                $rate  = (string) $row->er_amount;
                $excess = bcsub($taxable, $min, 4);
                $tax = bcadd($fixed, bcmul($excess, $rate, 4), 4);
                return self::round2($tax);
            }
        }

        // Above the highest bracket: use the top bracket.
        $top = $brackets->last();
        if ($top) {
            $excess = bcsub($taxable, (string) $top->bracket_min, 4);
            $tax = bcadd((string) $top->ee_amount, bcmul($excess, (string) $top->er_amount, 4), 4);
            return self::round2($tax);
        }

        return '0.00';
    }

    private static function round2(string $v): string
    {
        $sign = bccomp($v, '0', 4) >= 0 ? '1' : '-1';
        $abs = ltrim($v, '-');
        $rounded = bcadd($abs, '0.005', 2);
        return $sign === '-1' ? bcmul($rounded, '-1', 2) : bcadd($rounded, '0', 2);
    }
}
