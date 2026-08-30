<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Common\Support\Money;
use App\Modules\HR\Models\Employee;
use Illuminate\Support\Facades\DB;

class BirAlphalistService
{
    /*
     * REC-06 scope note — DAT/eBIRForms deferral (DO NOT fabricate):
     * The BIR Alphalist `.DAT` deliverable (fixed field order, header/trailer
     * records, control totals, ATC codes) and the eBIRForms XML are NOT
     * implemented here. The authoritative BIR DAT/ATC specification is not
     * present in this repo, and a guessed layout would produce a file that
     * looks official but fails eBIRForms validation — worse than an honest CSV
     * for a tax filing. This service therefore emits a plain per-employee CSV.
     * The DAT/XML encoders are deferred pending the official BIR spec sheet.
     */

    /**
     * Aggregate all finalized/disbursed regular payroll rows for a given year
     * and return an array suitable for CSV export.
     *
     * TIN is stored encrypted via Laravel's `encrypted` cast (AES-256-CBC).
     * We load Employee models via Eloquent so the cast decrypts TIN automatically.
     * Do NOT use pgcrypto — that is a separate mechanism.
     *
     * Money stays a decimal STRING end to end. It used to pass through
     * round((float) …) and `taxable_income` was computed as a float subtraction
     * of two float-cast sums, which is money arithmetic in binary floating point
     * inside a filed tax return: at company-wide annual totals the sum can land
     * a cent away from the payroll rows it is meant to report, and the resulting
     * 2316 disagrees with the ledger with nothing to explain the difference.
     * decimal(15,2) exists precisely to prevent that.
     *
     * @return array<int, array{
     *   tin: string, last_name: string, first_name: string, middle_name: string,
     *   employee_no: string, total_gross: string, total_deductions: string,
     *   taxable_income: string, total_withheld_tax: string
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

        return $rows->map(function ($r) use ($employees): array {
            $gross = Money::round2((string) $r->total_gross);
            $deductions = Money::round2((string) $r->total_deductions);
            $taxable = Money::sub($gross, $deductions);
            if (Money::lt($taxable, '0')) {
                $taxable = Money::zero();
            }

            return [
                'tin'                => (string) ($employees[$r->employee_id]?->tin ?? ''),
                'last_name'          => strtoupper((string) $r->last_name),
                'first_name'         => strtoupper((string) $r->first_name),
                'middle_name'        => strtoupper((string) $r->middle_name),
                'employee_no'        => (string) $r->employee_no,
                'total_gross'        => $gross,
                'total_deductions'   => $deductions,
                'taxable_income'     => $taxable,
                'total_withheld_tax' => Money::round2((string) $r->total_withheld_tax),
            ];
        })->toArray();
    }

    /**
     * Render the alphalist data as a BIR 2316-compatible CSV string (CRLF line endings).
     *
     * @param array<int, array{
     *   tin: string, last_name: string, first_name: string, middle_name: string,
     *   employee_no: string, total_gross: string, total_deductions: string,
     *   taxable_income: string, total_withheld_tax: string
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
                // Money::round2 already fixes the scale at 2, so the filed figure
                // is the persisted decimal verbatim — no float round-trip.
                Money::round2((string) $row['total_gross']),
                Money::round2((string) $row['total_deductions']),
                Money::round2((string) $row['taxable_income']),
                Money::round2((string) $row['total_withheld_tax']),
            ]);
        }

        return implode("\r\n", $lines);
    }
}
