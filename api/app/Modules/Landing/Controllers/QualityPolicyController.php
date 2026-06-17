<?php

declare(strict_types=1);

namespace App\Modules\Landing\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class QualityPolicyController
{
    public function download(): Response
    {
        $pdf = Pdf::loadView('pdf.quality-policy', [
            // Fixed approval/effective date of the controlled document (Rev. A);
            // `generatedAt` is only the "printed on" stamp in the footer.
            'effectiveDate' => 'January 2025',
            'generatedAt'   => now()->format('d F Y'),
        ]);

        return $pdf->download('ogami-quality-policy.pdf');
    }
}
