<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services\Statutory;

use App\Modules\HR\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * PhilHealth RF-1 — Employer Remittance Report. One line per employee with the
 * EE/ER premium shares for the given month.
 */
class PhilhealthRf1Service
{
    /**
     * @return array<int, array{philhealth_no:string,last_name:string,first_name:string,ee:float,er:float,total:float}>
     */
    public function generate(int $year, int $month): array
    {
        $rows = DB::table('payrolls as p')
            ->join('payroll_periods as pp', 'pp.id', '=', 'p.payroll_period_id')
            ->join('employees as e', 'e.id', '=', 'p.employee_id')
            ->whereIn('pp.status', ['finalized', 'disbursed'])
            ->where('pp.is_thirteenth_month', false)
            ->whereNull('p.error_message')
            ->whereYear('pp.period_start', $year)
            ->whereMonth('pp.period_start', $month)
            ->whereNull('e.deleted_at')
            ->groupBy('e.id', 'e.last_name', 'e.first_name')
            ->select([
                'e.id as employee_id',
                'e.last_name',
                'e.first_name',
                DB::raw('COALESCE(SUM(p.philhealth_ee), 0) as ee'),
                DB::raw('COALESCE(SUM(p.philhealth_er), 0) as er'),
            ])
            ->orderBy('e.last_name')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $numbers = Employee::withTrashed()
            ->whereIn('id', $rows->pluck('employee_id')->all())
            ->select(['id', 'philhealth_no'])
            ->get()->keyBy('id');

        return $rows->map(fn ($r) => [
            'philhealth_no' => (string) ($numbers[$r->employee_id]?->philhealth_no ?? ''),
            'last_name'     => strtoupper((string) $r->last_name),
            'first_name'    => strtoupper((string) $r->first_name),
            'ee'            => round((float) $r->ee, 2),
            'er'            => round((float) $r->er, 2),
            'total'         => round((float) $r->ee + (float) $r->er, 2),
        ])->toArray();
    }

    /**
     * @param array<int, array{philhealth_no:string,last_name:string,first_name:string,ee:float,er:float,total:float}> $data
     */
    public function toCsv(array $data): string
    {
        $lines = ['PhilHealth No,Last Name,First Name,EE Share,ER Share,Total'];
        foreach ($data as $row) {
            $lines[] = implode(',', [
                '"'.str_replace('"', '""', $row['philhealth_no']).'"',
                '"'.str_replace('"', '""', $row['last_name']).'"',
                '"'.str_replace('"', '""', $row['first_name']).'"',
                number_format($row['ee'], 2, '.', ''),
                number_format($row['er'], 2, '.', ''),
                number_format($row['total'], 2, '.', ''),
            ]);
        }

        return implode("\r\n", $lines);
    }
}
