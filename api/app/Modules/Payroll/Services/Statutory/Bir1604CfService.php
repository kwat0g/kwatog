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
     * @return array{year: int, headcount: int, total_compensation: float, total_withheld: float}
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
            ->selectRaw('COALESCE(SUM(p.withholding_tax), 0) as total_withheld')
            ->first();

        return [
            'year'               => $year,
            'headcount'          => (int) ($row->headcount ?? 0),
            'total_compensation' => round((float) ($row->total_compensation ?? 0), 2),
            'total_withheld'     => round((float) ($row->total_withheld ?? 0), 2),
        ];
    }

    /**
     * @param array{year:int,headcount:int,total_compensation:float,total_withheld:float} $data
     */
    public function toCsv(array $data): string
    {
        return implode("\r\n", [
            'Form,Year,Headcount,Total Compensation,Total Tax Withheld',
            implode(',', [
                'BIR-1604-CF',
                (string) $data['year'],
                (string) $data['headcount'],
                number_format($data['total_compensation'], 2, '.', ''),
                number_format($data['total_withheld'], 2, '.', ''),
            ]),
        ]);
    }
}
