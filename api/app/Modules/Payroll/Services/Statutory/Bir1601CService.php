<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services\Statutory;

use Illuminate\Support\Facades\DB;

/**
 * BIR Form 1601-C — Monthly Remittance Return of Income Taxes Withheld on
 * Compensation. Aggregates all finalized/disbursed regular payroll rows whose
 * period falls in the given calendar month.
 */
class Bir1601CService
{
    /**
     * REC-06 — the return is enriched with a taxable-vs-exempt split and a
     * tax-due-vs-withheld reconciliation, all computed from columns that exist
     * on the `payrolls` table:
     *
     *  - Non-taxable = the mandatory employee-share statutory contributions
     *    (SSS EE + PhilHealth EE + Pag-IBIG EE), which are exempt from
     *    withholding tax under the Tax Code. Loan/other deductions are NOT
     *    exempt (they are post-tax) so they stay inside the taxable base.
     *  - Taxable = gross compensation minus those exempt contributions.
     *  - tax_due is set equal to tax_withheld here: the true annualized
     *    tax-due reconciliation (year-end adjustment) is a 1604-CF / 2316
     *    concern, not a monthly-remittance one — no annualization formula is
     *    fabricated at this monthly level.
     *
     * Keys are a superset of the original shape (nothing renamed/removed) so
     * existing callers/tests keep working.
     *
     * @return array{
     *     period: string, headcount: int,
     *     total_compensation: float, taxable_compensation: float,
     *     non_taxable_compensation: float, total_withheld: float,
     *     tax_due: float
     * }
     */
    public function generate(int $year, int $month): array
    {
        $row = DB::table('payrolls as p')
            ->join('payroll_periods as pp', 'pp.id', '=', 'p.payroll_period_id')
            ->whereIn('pp.status', ['finalized', 'disbursed'])
            ->where('pp.is_thirteenth_month', false)
            ->whereNull('p.error_message')
            ->whereYear('pp.period_start', $year)
            ->whereMonth('pp.period_start', $month)
            ->selectRaw('COUNT(DISTINCT p.employee_id) as headcount')
            ->selectRaw('COALESCE(SUM(p.gross_pay), 0) as total_compensation')
            ->selectRaw('COALESCE(SUM(p.sss_ee + p.philhealth_ee + p.pagibig_ee), 0) as non_taxable_compensation')
            ->selectRaw('COALESCE(SUM(p.withholding_tax), 0) as total_withheld')
            ->first();

        $total   = round((float) ($row->total_compensation ?? 0), 2);
        $exempt  = round((float) ($row->non_taxable_compensation ?? 0), 2);
        $withheld = round((float) ($row->total_withheld ?? 0), 2);

        return [
            'period'                   => sprintf('%04d-%02d', $year, $month),
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
     *     period: string, headcount: int,
     *     total_compensation: float, taxable_compensation?: float,
     *     non_taxable_compensation?: float, total_withheld: float,
     *     tax_due?: float
     * } $data
     */
    public function toCsv(array $data): string
    {
        $lines = [
            'Form,Period,Headcount,Total Compensation,Taxable Compensation,Non-Taxable Compensation,Tax Due,Total Tax Withheld',
            implode(',', [
                'BIR-1601-C',
                $data['period'],
                (string) $data['headcount'],
                number_format($data['total_compensation'], 2, '.', ''),
                number_format((float) ($data['taxable_compensation'] ?? $data['total_compensation']), 2, '.', ''),
                number_format((float) ($data['non_taxable_compensation'] ?? 0), 2, '.', ''),
                number_format((float) ($data['tax_due'] ?? $data['total_withheld']), 2, '.', ''),
                number_format($data['total_withheld'], 2, '.', ''),
            ]),
        ];

        return implode("\r\n", $lines);
    }
}
