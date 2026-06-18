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
     * @return array{period: string, headcount: int, total_compensation: float, total_withheld: float}
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
            ->selectRaw('COALESCE(SUM(p.withholding_tax), 0) as total_withheld')
            ->first();

        return [
            'period'             => sprintf('%04d-%02d', $year, $month),
            'headcount'          => (int) ($row->headcount ?? 0),
            'total_compensation' => round((float) ($row->total_compensation ?? 0), 2),
            'total_withheld'     => round((float) ($row->total_withheld ?? 0), 2),
        ];
    }

    /**
     * @param array{period: string, headcount: int, total_compensation: float, total_withheld: float} $data
     */
    public function toCsv(array $data): string
    {
        $lines = [
            'Form,Period,Headcount,Total Compensation,Total Tax Withheld',
            implode(',', [
                'BIR-1601-C',
                $data['period'],
                (string) $data['headcount'],
                number_format($data['total_compensation'], 2, '.', ''),
                number_format($data['total_withheld'], 2, '.', ''),
            ]),
        ];

        return implode("\r\n", $lines);
    }
}
