<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services\Statutory;

use Illuminate\Support\Facades\DB;

/**
 * BIR Form 1604-CF — Annual Information Return of Income Taxes Withheld on
 * Compensation. Year-level totals; the per-employee detail is the Alphalist
 * (see BirAlphalistService).
 */
class Bir1604CfService
{
    /**
     * REC-06 — enriched with the same taxable-vs-exempt split as the monthly
     * 1601-C, aggregated across the whole calendar year. The exempt portion is
     * the mandatory employee-share statutory contributions (SSS/PhilHealth/
     * Pag-IBIG EE) present on the `payrolls` table. No column outside the real
     * schema is used; keys are a superset of the original shape.
     *
     * @return array{
     *     year: int, headcount: int,
     *     total_compensation: float, taxable_compensation: float,
     *     non_taxable_compensation: float, total_withheld: float,
     *     tax_due: float
     * }
     */
    public function generate(int $year): array
    {
        $row = DB::table('payrolls as p')
            ->join('payroll_periods as pp', 'pp.id', '=', 'p.payroll_period_id')
            ->whereIn('pp.status', ['finalized', 'disbursed'])
            ->where('pp.is_thirteenth_month', false)
            ->whereNull('p.error_message')
            ->whereYear('pp.period_start', $year)
            ->selectRaw('COUNT(DISTINCT p.employee_id) as headcount')
            ->selectRaw('COALESCE(SUM(p.gross_pay), 0) as total_compensation')
            ->selectRaw('COALESCE(SUM(p.sss_ee + p.philhealth_ee + p.pagibig_ee), 0) as non_taxable_compensation')
            ->selectRaw('COALESCE(SUM(p.withholding_tax), 0) as total_withheld')
            ->first();

        $total    = round((float) ($row->total_compensation ?? 0), 2);
        $exempt   = round((float) ($row->non_taxable_compensation ?? 0), 2);
        $withheld = round((float) ($row->total_withheld ?? 0), 2);

        return [
            'year'                     => $year,
            'headcount'                => (int) ($row->headcount ?? 0),
            'total_compensation'       => $total,
            'taxable_compensation'     => round($total - $exempt, 2),
            'non_taxable_compensation' => $exempt,
            'total_withheld'           => $withheld,
            'tax_due'                  => $withheld,
        ];
    }

    /**
     * @param array{
     *     year:int, headcount:int,
     *     total_compensation:float, taxable_compensation?:float,
     *     non_taxable_compensation?:float, total_withheld:float,
     *     tax_due?:float
     * } $data
     */
    public function toCsv(array $data): string
    {
        return implode("\r\n", [
            'Form,Year,Headcount,Total Compensation,Taxable Compensation,Non-Taxable Compensation,Tax Due,Total Tax Withheld',
            implode(',', [
                'BIR-1604-CF',
                (string) $data['year'],
                (string) $data['headcount'],
                number_format($data['total_compensation'], 2, '.', ''),
                number_format((float) ($data['taxable_compensation'] ?? $data['total_compensation']), 2, '.', ''),
                number_format((float) ($data['non_taxable_compensation'] ?? 0), 2, '.', ''),
                number_format((float) ($data['tax_due'] ?? $data['total_withheld']), 2, '.', ''),
                number_format($data['total_withheld'], 2, '.', ''),
            ]),
        ]);
    }
}
