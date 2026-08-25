<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Enums\DocumentType;
use App\Common\Services\DocumentVaultService;
use App\Common\Services\Pdf\PdfRenderService;
use App\Common\Support\ApprovalSignatureBuilder;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseOrderPdfService
{
    public function __construct(
        private readonly PdfRenderService $renderer,
        private readonly DocumentVaultService $vault,
    ) {}

    public function render(PurchaseOrder $po): StreamedResponse
    {
        $po->loadMissing([
            'vendor',
            'items.item',
            'creator',
            'approver',
            // Sprint P9 — for the 4-tier signature block.
            'approvalRecords.approver:id,name',
        ]);
        $bytes = $this->renderer->render('pdf.purchase-order', [
            'po'        => $po,
            'now'       => now(),
            // Sprint P9 — drives the new signature-block partial.
            'approvals' => ApprovalSignatureBuilder::for($po, $po->creator),
        ], ['orientation' => 'portrait', 'title' => DocumentType::PurchaseOrder->label()]);
        $actor = auth()->user();
        $user = $actor instanceof User ? $actor : null;
        $document = $this->vault->store($bytes, DocumentType::PurchaseOrder, $po, $user);

        return $this->vault->streamInline($document);
    }
}
