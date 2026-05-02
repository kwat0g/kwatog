<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services\Government;

use App\Modules\Payroll\Enums\ContributionAgency;
use App\Modules\Payroll\Services\GovernmentContributionTableService;

/**
 * Pag-IBIG (HDMF) Fund contribution.
 *
 * Two rate-based brackets. Basis is min(salary, ceiling=10,000).
 *   ee = basis × bracket.ee_amount  (rate)
 *   er = basis × bracket.er_amount  (rate)
 *
 * Max EE = 200, Max ER = 200 at the 10,000 ceiling × 2%.
 */
class PagibigComputationService
{
    private const CEILING = '10000.00';

    public function __construct(private readonly GovernmentContributionTableService $tables) {}

    /**
     * @return array{ee: string, er: string}
     */
    public function compute(string|float|int $monthlySalary): array
    {
        $salary = (string) $monthlySalary;
        if (bccomp($salary, '0', 2) <= 0) {
            return ['ee' => '0.00', 'er' => '0.00'];
        }

        // Cap salary at the project ceiling.
        $basis = bccomp($salary, self::CEILING, 2) > 0 ? self::CEILING : $salary;

        $brackets = $this->tables->activeBrackets(ContributionAgency::Pagibig);
        foreach ($brackets as $row) {
            $min = (string) $row->bracket_min;
            $max = (string) $row->bracket_max;
            if (bccomp($basis, $min, 2) >= 0 && bccomp($basis, $max, 2) <= 0) {
                $ee = bcmul($basis, (string) $row->ee_amount, 4);
                $er = bcmul($basis, (string) $row->er_amount, 4);
                return [
                    'ee' => self::round2($ee),
                    'er' => self::round2($er),
                ];
            }
        }

        return ['ee' => '0.00', 'er' => '0.00'];
    }

    private static function round2(string $v): string
    {
        $sign = bccomp($v, '0', 4) >= 0 ? '1' : '-1';
        $abs = ltrim($v, '-');
        $rounded = bcadd($abs, '0.005', 2);
        return $sign === '-1' ? bcmul($rounded, '-1', 2) : bcadd($rounded, '0', 2);
    }
}
