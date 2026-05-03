<?php

declare(strict_types=1);

namespace App\Common\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * Sprint 8 — Task 76. Renders a list of homogenous documents into one PDF.
 *
 * Strategy: render each document as its own page set, then concatenate via
 * a single Blade wrapper that loops over the documents using @include with
 * page-break-after CSS between them. This avoids the need for a binary
 * pdfunite dependency and keeps everything inside the existing DomPDF stack.
 *
 * Renderers map:
 *   'purchase_order'       → resources/views/pdf/purchase-order.blade.php
 *   'bill'                 → resources/views/pdf/bill.blade.php
 *   'invoice'              → resources/views/pdf/invoice.blade.php
 *   'employee_loan'        → resources/views/pdf/employee-loan.blade.php   (lazy — render only if exists)
 *   'cash_advance'         → resources/views/pdf/cash-advance.blade.php    (same)
 *   'purchase_request'     → resources/views/pdf/purchase-request.blade.php
 *   'clearance'            → resources/views/pdf/clearance.blade.php       (Task 71)
 */
class BulkPdfService
{
    /**
     * Document type → Blade view. Only types whose Blade exists are exposed
     * here; the controller's Rule::in() whitelist must match this map exactly.
     * Adding more is a one-line change once the per-type template is shipped.
     */
    private const RENDERERS = [
        'purchase_order'   => 'pdf.purchase-order',
        'bill'             => 'pdf.bill',
        'invoice'          => 'pdf.invoice',
    ];

    /**
     * @param string                       $type        document type (see RENDERERS)
     * @param iterable<int, array<string,mixed>> $payloads   per-document data arrays
     */
    public function render(string $type, iterable $payloads): Response
    {
        if (! isset(self::RENDERERS[$type])) {
            throw new RuntimeException("Unsupported bulk document type: {$type}");
        }
        $view = self::RENDERERS[$type];
        $payloadsArr = is_array($payloads) ? $payloads : iterator_to_array($payloads);
        if (! count($payloadsArr)) {
            throw new RuntimeException('No documents to render.');
        }

        $pdf = Pdf::loadView('pdf._bulk', [
            'view'     => $view,
            'payloads' => $payloadsArr,
        ])->setPaper('a4', 'portrait');

        $filename = 'bulk-'.$type.'-'.now()->format('YmdHis').'.pdf';
        return response($pdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
