<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Exports\Government;

use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Series E (Task E2) — SSS R-3 (Contribution Collection List).
 *
 * IMPORTANT — column order is dictated by SSS form. DO NOT consult the
 * configurable column registry; the agency rejects files that don't
 * match the spec. Reference: SSS R-3 (Reg. No. R-3) layout.
 */
class SssR3Export implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle, ShouldAutoSize
{
    public function __construct(private readonly PayrollPeriod $period) {}

    public function collection(): Collection
    {
        return Payroll::query()
            ->with(['employee'])
            ->where('payroll_period_id', $this->period->id)
            ->whereNull('error_message')
            ->get();
    }

    /** @return array<int, string> */
    public function headings(): array
    {
        return [
            'SS Number',
            'Last Name',
            'First Name',
            'Middle Name',
            'Total Monthly Compensation',
            'EE Share',
            'ER Share',
            'EC Share',
            'Total Contribution',
            'Remarks',
        ];
    }

    /**
     * @param  Payroll  $row
     * @return array<int, mixed>
     */
    public function map($row): array
    {
        $emp = $row->employee instanceof Employee ? $row->employee : null;
        $sss = $emp?->sss_no ?? '';

        // Derive contribution components — uses existing payroll deduction
        // details where available; falls back to flat SSS amount.
        $eeShare = (float) ($row->sss_ee ?? 0);
        $erShare = (float) ($row->sss_er ?? 0);
        $ecShare = 0.0; // EC is not tracked separately on payrolls; report 0 until modelled.
        $monthly = (float) ($row->basic_pay ?? $row->gross_pay ?? 0);

        return [
            (string) $sss,
            (string) ($emp?->last_name ?? ''),
            (string) ($emp?->first_name ?? ''),
            (string) ($emp?->middle_name ?? ''),
            number_format((float) $monthly, 2, '.', ''),
            number_format((float) $eeShare, 2, '.', ''),
            number_format((float) $erShare, 2, '.', ''),
            number_format((float) $ecShare, 2, '.', ''),
            number_format((float) ($eeShare + $erShare + $ecShare), 2, '.', ''),
            '',
        ];
    }

    public function title(): string
    {
        return 'SSS R-3 ' . $this->period->period_start?->format('Y-m');
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
