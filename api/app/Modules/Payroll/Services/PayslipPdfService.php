<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Common\Enums\DocumentType;
use App\Common\Models\Document;
use App\Common\Services\DocumentVaultService;
use App\Common\Services\Pdf\PdfRenderService;
use App\Modules\Auth\Models\User;
use App\Modules\Payroll\Models\Payroll;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Renders + persists payslips through the central document vault. Direct
 * DomPDF calls have been removed — every payslip now produces an audit
 * trail in the `documents` table (Series E / Task E1).
 */
class PayslipPdfService
{
    public function __construct(
        private readonly PdfRenderService $renderer,
        private readonly DocumentVaultService $vault,
    ) {}

    /**
     * Build the payslip PDF binary (no persistence).
     */
    public function generate(Payroll $payroll, ?User $generator = null): string
    {
        $payroll->loadMissing(['employee.department', 'employee.position', 'period', 'deductionDetails']);

        return $this->renderer->render(
            'pdf.payslip',
            [
                'payroll'  => $payroll,
                'employee' => $payroll->employee,
                'period'   => $payroll->period,
                'details'  => $payroll->deductionDetails,
            ],
            [
                'paper'         => 'a5',
                'orientation'   => 'portrait',
                'confidential'  => true,
                'generator'     => $generator,
                'title'         => 'Payslip',
            ],
        );
    }

    public function filename(Payroll $payroll): string
    {
        $payroll->loadMissing('period');
        $no = $payroll->employee?->employee_no ?? 'EMP';
        $start = $payroll->period?->period_start?->format('Y-m-d') ?? 'period';
        return "Payslip_{$no}_{$start}.pdf";
    }

    /**
     * Render + persist to vault, return the vault row.
     */
    public function generateAndStore(Payroll $payroll, ?User $generator = null): Document
    {
        $bytes = $this->generate($payroll, $generator);
        return $this->vault->store($bytes, DocumentType::Payslip, $payroll, $generator, true);
    }

    /**
     * Stream as attachment. Persists to vault as a side effect so every
     * download leaves an audit trail.
     */
    public function stream(Payroll $payroll, ?User $generator = null): StreamedResponse
    {
        $doc = $this->generateAndStore($payroll, $generator);
        return $this->vault->streamDownload($doc);
    }
}
