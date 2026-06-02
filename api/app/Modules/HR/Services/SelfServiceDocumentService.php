<?php

declare(strict_types=1);

namespace App\Modules\HR\Services;

use App\Common\Services\Pdf\PdfRenderService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Models\Payroll;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Task SS3 — on-demand employee documents the portal generates without HR
 * involvement: employment certificate, government-contribution certificates,
 * and BIR 2316. Every method is scoped to a single employee (the caller
 * resolves the session employee) and streams the PDF straight back.
 *
 * These are personal certificates, not company records, so they bypass the
 * document vault — there is no cross-employee exposure and no audit value in
 * persisting a copy the employee can re-generate at will.
 */
class SelfServiceDocumentService
{
    public function __construct(private readonly PdfRenderService $renderer) {}

    /* ─── Employment certificate ─────────────────────────────────────── */

    public function employmentCertificate(Employee $employee, ?User $generator = null, bool $withSalary = false): StreamedResponse
    {
        $employee->loadMissing(['department', 'position']);
        $now = now();

        $salaryText = null;
        $salaryBasis = 'salary';
        if ($withSalary) {
            if ($employee->pay_type?->value === 'monthly' && $employee->basic_monthly_salary !== null) {
                $salaryText = '₱ '.number_format((float) $employee->basic_monthly_salary, 2).' per month';
                $salaryBasis = 'monthly salary';
            } elseif ($employee->daily_rate !== null) {
                $salaryText = '₱ '.number_format((float) $employee->daily_rate, 2).' per day';
                $salaryBasis = 'daily rate';
            }
        }

        $bytes = $this->renderer->render('pdf.employment-certificate', [
            'employee_name'     => $employee->full_name,
            'employee_no'       => $employee->employee_no,
            'position'          => $employee->position?->title ?? '—',
            'department'        => $employee->department?->name ?? '—',
            'date_hired'        => optional($employee->date_hired)->format('F j, Y') ?? '—',
            'employment_status' => strtoupper($employee->employment_type?->label() ?? 'REGULAR'),
            'show_salary'       => $withSalary && $salaryText !== null,
            'salary_text'       => $salaryText,
            'salary_basis'      => $salaryBasis,
            'issued_day'        => $now->format('jS'),
            'issued_month_year' => $now->format('F Y'),
        ], [
            'paper'     => 'a4',
            'generator' => $generator,
            'title'     => 'Certificate of Employment',
        ]);

        return $this->streamInline($bytes, "Employment-Certificate-{$employee->employee_no}.pdf");
    }

    /* ─── Government contribution certificates ───────────────────────── */

    /**
     * @param  'sss'|'philhealth'|'pagibig'  $type
     */
    public function contributionCertificate(Employee $employee, string $type, int $year, ?User $generator = null): StreamedResponse
    {
        [$title, $label, $column, $idLabel, $idValue] = match ($type) {
            'sss'        => ['Certificate of SSS Contributions', 'SSS', 'sss_ee', 'SSS No.', $employee->sss_no],
            'philhealth' => ['Certificate of PhilHealth Contributions', 'PhilHealth', 'philhealth_ee', 'PhilHealth No.', $employee->philhealth_no],
            'pagibig'    => ['Certificate of Pag-IBIG Contributions', 'Pag-IBIG', 'pagibig_ee', 'Pag-IBIG No.', $employee->pagibig_no],
            default      => abort(404, 'Unknown contribution type.'),
        };

        [$rows, $total] = $this->yearlyContributions($employee, $column, $year);

        $bytes = $this->renderer->render('pdf.contribution-certificate', [
            'cert_title'         => $title,
            'contribution_label' => $label,
            'id_label'           => $idLabel,
            'id_value'           => $idValue, // already decrypted via cast; full value on the employee's own cert
            'employee_name'      => $employee->full_name,
            'employee_no'        => $employee->employee_no,
            'year'               => $year,
            'rows'               => $rows,
            'total'              => $total,
        ], [
            'paper'        => 'a4',
            'confidential' => true,
            'generator'    => $generator,
            'title'        => $title,
        ]);

        return $this->streamInline($bytes, ucfirst($type)."-Contributions-{$year}-{$employee->employee_no}.pdf");
    }

    /* ─── BIR 2316 ───────────────────────────────────────────────────── */

    public function bir2316(Employee $employee, int $year, ?User $generator = null): StreamedResponse
    {
        $totals = $this->yearlyTaxSummary($employee, $year);

        $bytes = $this->renderer->render('pdf.bir-2316', [
            'employee_name' => $employee->full_name,
            'employee_no'   => $employee->employee_no,
            'tin'           => $employee->tin,
            'year'          => $year,
            'gross'         => $totals['gross'],
            'sss'           => $totals['sss'],
            'philhealth'    => $totals['philhealth'],
            'pagibig'       => $totals['pagibig'],
            'mandatory'     => $totals['mandatory'],
            'taxable'       => $totals['taxable'],
            'tax_withheld'  => $totals['tax_withheld'],
        ], [
            'paper'        => 'a4',
            'confidential' => true,
            'generator'    => $generator,
            'title'        => 'BIR Form 2316',
        ]);

        return $this->streamInline($bytes, "BIR-2316-{$year}-{$employee->employee_no}.pdf");
    }

    /* ─── Aggregation helpers ────────────────────────────────────────── */

    /**
     * Per-period employee-share rows for one contribution column in a year.
     *
     * @return array{0: array<int, array{period:string, amount:float}>, 1: float}
     */
    private function yearlyContributions(Employee $employee, string $column, int $year): array
    {
        if (! Schema::hasTable('payrolls')) {
            return [[], 0.0];
        }

        $payrolls = Payroll::query()
            ->where('employee_id', $employee->id)
            ->whereHas('period', fn ($q) => $q->whereYear('period_start', $year))
            ->with('period:id,period_start,period_end')
            ->whereNull('error_message')
            ->get();

        $rows = [];
        $total = 0.0;
        foreach ($payrolls->sortBy(fn ($p) => $p->period?->period_start) as $p) {
            $amount = (float) ($p->{$column} ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $total += $amount;
            $rows[] = [
                'period' => $p->period?->period_start?->format('M j').' – '.$p->period?->period_end?->format('M j, Y') ?? '—',
                'amount' => $amount,
            ];
        }

        return [$rows, $total];
    }

    /**
     * Year-to-date compensation + tax totals for BIR 2316.
     *
     * @return array<string, float>
     */
    private function yearlyTaxSummary(Employee $employee, int $year): array
    {
        $sss = $philhealth = $pagibig = $gross = $tax = 0.0;

        if (Schema::hasTable('payrolls')) {
            $payrolls = Payroll::query()
                ->where('employee_id', $employee->id)
                ->whereHas('period', fn ($q) => $q->whereYear('period_start', $year))
                ->whereNull('error_message')
                ->get();

            foreach ($payrolls as $p) {
                $gross      += (float) $p->gross_pay;
                $sss        += (float) $p->sss_ee;
                $philhealth += (float) $p->philhealth_ee;
                $pagibig    += (float) $p->pagibig_ee;
                $tax        += (float) $p->withholding_tax;
            }
        }

        $mandatory = $sss + $philhealth + $pagibig;

        return [
            'gross'        => $gross,
            'sss'          => $sss,
            'philhealth'   => $philhealth,
            'pagibig'      => $pagibig,
            'mandatory'    => $mandatory,
            'taxable'      => max(0.0, $gross - $mandatory),
            'tax_withheld' => $tax,
        ];
    }

    private function streamInline(string $bytes, string $filename): StreamedResponse
    {
        return new StreamedResponse(fn () => print $bytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Length'      => (string) strlen($bytes),
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $filename).'"',
            'Cache-Control'       => 'private, no-store, max-age=0, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
