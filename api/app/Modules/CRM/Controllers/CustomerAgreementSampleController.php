<?php

declare(strict_types=1);

namespace App\Modules\CRM\Controllers;

use App\Common\Services\Pdf\PdfRenderService;
use App\Modules\CRM\Services\CustomerAgreementSampleGenerator;
use Illuminate\Http\Response;

final class CustomerAgreementSampleController
{
    public function __invoke(
        CustomerAgreementSampleGenerator $samples,
        PdfRenderService $pdf,
    ): Response {
        $sample = $samples->generate();
        $bytes = $pdf->render('crm::pdf.customer-agreement-sample', ['sample' => $sample], [
            'title' => 'Sample Customer Supply Agreement',
            'watermark_text' => 'FICTIONAL SAMPLE',
        ]);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="customer-agreement-'.$sample['reference'].'.pdf"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
