<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Enums\DocumentType;
use App\Common\Services\DocumentVaultService;
use App\Common\Services\Pdf\PdfRenderService;
use App\Common\Support\ApprovalSignatureBuilder;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sprint P9 — render a Purchase Request as a single-page A4 PDF with the
 * shared 4-tier approval signature block.
 *
 * Mirrors PurchaseOrderPdfService for consistency. The blade lives at
 * `resources/views/pdf/purchase-request.blade.php`.
 */
class PurchaseRequestPdfService
{
    public function __construct(
        private readonly PdfRenderService $renderer,
        private readonly DocumentVaultService $vault,
    ) {}

    public function render(PurchaseRequest $pr): StreamedResponse
    {
        $pr->loadMissing([
            'requester:id,name',
            'department:id,name,code',
            'items.item:id,code,name,unit_of_measure',
            'items.suggestedVendor:id,name',
            'approvalRecords.approver:id,name',
        ]);

        $bytes = $this->renderer->render('pdf.purchase-request', [
            'pr'        => $pr,
            'now'       => now(),
            'approvals' => ApprovalSignatureBuilder::for($pr, $pr->requester),
        ], ['orientation' => 'portrait', 'title' => DocumentType::PurchaseRequest->label()]);
        $actor = auth()->user();
        $user = $actor instanceof User ? $actor : null;
        $document = $this->vault->store($bytes, DocumentType::PurchaseRequest, $pr, $user);

        return $this->vault->streamInline($document);
    }
}
