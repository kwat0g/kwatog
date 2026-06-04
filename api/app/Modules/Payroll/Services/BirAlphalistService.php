<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\HR\Models\Employee;
use Illuminate\Support\Facades\DB;

class BirAlphalistService
{
    /**
     * Aggregate all finalized/disbursed regular payroll rows for a given year
     * and return an array suitable for CSV export.
     *
     * TIN is stored encrypted via Laravel's `encrypted` cast (AES-256-CBC).
     * We load Employee models via Eloquent so the cast decrypts TIN automatically.
     * Do NOT use pgcrypto — that is a separate mechanism.
     *
     * @return array<int, array{
     *   tin: string, last_name: string, first_name: string, middle_name: string,
     *   employee_no: string, total_gross: float, total_deductions: float,
     *   taxable_income: float, total_withheld_tax: float
     * }>
     */
    public function generate(int $year): array
    {
        // Aggregate using a DB query for performance (avoids loading all Payroll models).
        // Excludes 13th-month periods (separate BIR treatment) and error rows.
        $rows = DB::table('payrolls as p')
            ->join('payroll_periods as pp', 'pp.id', '=', 'p.payroll_period_id')
            ->join('employees as e', 'e.id', '=', 'p.employee_id')
            ->whereIn('pp.status', ['finalized', 'disbursed'])
            ->whereNull('p.error_message')
            ->whereYear('pp.period_start', $year)
            ->where('pp.is_thirteenth_month', false)
            ->whereNull('e.deleted_at')
            ->select([
                'e.id as employee_id',
                'e.employee_no',
                'e.first_name',
                'e.last_name',
                DB::raw("COALESCE(e.middle_name, '') as middle_name"),
                DB::raw('SUM(p.gross_pay) as total_gross'),
                DB::raw('SUM(p.total_deductions) as total_deductions'),
                DB::raw('COALESCE(SUM(p.withholding_tax), 0) as total_withheld_tax'),
            ])
            ->groupBy('e.id', 'e.employee_no', 'e.first_name', 'e.last_name', 'e.middle_name')
            ->orderBy('e.last_name')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // Load Employee models to decrypt TIN via Eloquent's `encrypted` cast.
        $employeeIds = $rows->pluck('employee_id')->toArray();
        $employees = Employee::withTrashed()
            ->whereIn('id', $employeeIds)
            ->select(['id', 'tin'])
            ->get()
            ->keyBy('id');

        return $rows->map(fn ($r) => [
            'tin'                => (string) ($employees[$r->employee_id]?->tin ?? ''),
            'last_name'          => strtoupper((string) $r->last_name),
            'first_name'         => strtoupper((string) $r->first_name),
            'middle_name'        => strtoupper((string) $r->middle_name),
            'employee_no'        => (string) $r->employee_no,
            'total_gross'        => round((float) $r->total_gross, 2),
            'total_deductions'   => round((float) $r->total_deductions, 2),
            'taxable_income'     => round(max(0.0, (float) $r->total_gross - (float) $r->total_deductions), 2),
            'total_withheld_tax' => round((float) $r->total_withheld_tax, 2),
        ])->toArray();
    }

    /**
     * Render the alphalist data as a BIR 2316-compatible CSV string (CRLF line endings).
     *
     * @param array<int, array{
     *   tin: string, last_name: string, first_name: string, middle_name: string,
     *   employee_no: string, total_gross: float, total_deductions: float,
     *   taxable_income: float, total_withheld_tax: float
     * }> $data
     */
    public function toCsv(array $data): string
    {
        $headers = [
            'TIN', 'Last Name', 'First Name', 'Middle Name', 'Employee No',
            'Total Gross Pay', 'Total Deductions', 'Taxable Income', 'Total Tax Withheld',
        ];
        $lines = [implode(',', $headers)];

        foreach ($data as $row) {
            $lines[] = implode(',', [
                '"'.str_replace('"', '""', (string) $row['tin']).'"',
                '"'.str_replace('"', '""', (string) $row['last_name']).'"',
                '"'.str_replace('"', '""', (string) $row['first_name']).'"',
                '"'.str_replace('"', '""', (string) $row['middle_name']).'"',
                '"'.str_replace('"', '""', (string) $row['employee_no']).'"',
                number_format($row['total_gross'], 2, '.', ''),
                number_format($row['total_deductions'], 2, '.', ''),
                number_format($row['taxable_income'], 2, '.', ''),
                number_format($row['total_withheld_tax'], 2, '.', ''),
            ]);
        }

        return implode("\r\n", $lines);
    }
}
