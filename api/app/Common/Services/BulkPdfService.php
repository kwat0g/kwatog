<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Enums\DocumentType;
use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\Pdf\PdfRenderService;
use App\Modules\Auth\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
 *
 * Lifecycle: the bytes go through PdfRenderService (so the wrapped
 * per-document Blades receive the same company letterhead, generator, and
 * watermark context every other official PDF gets) and then through
 * DocumentVaultService, so a bulk print leaves a `documents` audit row with a
 * checksum and is re-downloadable through the central permission-checked
 * route instead of being a one-shot untracked byte stream.
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
        'purchase_request' => 'pdf.purchase-request', // Sprint P9
        'bill'             => 'pdf.bill',
        'invoice'          => 'pdf.invoice',
    ];

    public function __construct(
        private readonly PdfRenderService $renderer,
        private readonly DocumentVaultService $vault,
    ) {}

    /**
     * @param string                       $type        document type (see RENDERERS)
     * @param iterable<int, array<string,mixed>> $payloads   per-document data arrays
     * @param User                         $requestedBy the acting user; a bulk
     *        print is an operator action, so the vault row is filed against the
     *        requester. A bulk artifact spans many records and therefore has no
     *        single owning business entity, and `documents.entity_type` /
     *        `entity_id` are NOT NULL. Access is gated by the `bulk_pdf`
     *        document-type permission (`admin.print.bulk`) in
     *        DocumentController::permissionFor(), never by entity ownership, so
     *        filing against the requester grants no extra reach.
     */
    public function render(string $type, iterable $payloads, User $requestedBy): StreamedResponse
    {
        if (! isset(self::RENDERERS[$type])) {
            throw new BusinessRuleException("Unsupported bulk document type: {$type}");
        }
        $view = self::RENDERERS[$type];
        $payloadsArr = is_array($payloads) ? $payloads : iterator_to_array($payloads);
        if (! count($payloadsArr)) {
            throw new BusinessRuleException('No documents to render.');
        }

        $bytes = $this->renderer->render('pdf._bulk', [
            'view'     => $view,
            'payloads' => $payloadsArr,
        ], [
            'title'     => 'Bulk '.ucwords(str_replace('_', ' ', $type)),
            'generator' => $requestedBy,
        ]);

        $document = $this->vault->store(
            $bytes,
            DocumentType::BulkPdf,
            $requestedBy,
            $requestedBy,
        );

        return $this->vault->streamDownload($document);
    }
}
