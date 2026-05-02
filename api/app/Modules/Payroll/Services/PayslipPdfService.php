<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Payroll\Models\Payroll;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayslipPdfService
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Build a PDF binary for one payroll row.
     */
    public function generate(Payroll $payroll, ?User $generator = null): string
    {
        $payroll->loadMissing(['employee.department', 'employee.position', 'period', 'deductionDetails']);

        $companyName    = (string) ($this->settings->get('company.name', 'Philippine Ogami Corporation'));
        $companyAddress = (string) ($this->settings->get('company.address', ''));
        $companyTin     = (string) ($this->settings->get('company.tin', ''));

        $pdf = Pdf::loadView('pdf.payslip', [
            'payroll'        => $payroll,
            'employee'       => $payroll->employee,
            'period'         => $payroll->period,
            'details'        => $payroll->deductionDetails,
            'companyName'    => $companyName,
            'companyAddress' => $companyAddress,
            'companyTin'     => $companyTin,
            'generator'      => $generator,
            'generatedAt'    => now(),
        ])->setPaper('a5', 'portrait');

        return $pdf->output();
    }

    public function filename(Payroll $payroll): string
    {
        $payroll->loadMissing('period');
        $no = $payroll->employee?->employee_no ?? 'EMP';
        $start = $payroll->period?->period_start?->format('Y-m-d') ?? 'period';
        return "Payslip_{$no}_{$start}.pdf";
    }

    /**
     * Stream the payslip PDF as an HTTP response (Content-Disposition: attachment).
     */
    public function stream(Payroll $payroll, ?User $generator = null): StreamedResponse
    {
        $bytes = $this->generate($payroll, $generator);
        $filename = $this->filename($payroll);

        return response()->streamDownload(
            fn () => print $bytes,
            $filename,
            [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ],
        );
    }
}
