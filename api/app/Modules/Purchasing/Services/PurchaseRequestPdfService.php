<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Services\SettingsService;
use App\Common\Support\ApprovalSignatureBuilder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sprint P9 — render a Purchase Request as a single-page A4 PDF with the
 * shared 4-tier approval signature block.
 *
 * Mirrors PurchaseOrderPdfService for consistency. The blade lives at
 * `resources/views/pdf/purchase-request.blade.php`.
 */
class PurchaseRequestPdfService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function render(PurchaseRequest $pr): Response
    {
        $pr->loadMissing([
            'requester:id,name',
            'department:id,name,code',
            'items.item:id,code,name,unit_of_measure',
            'approvalRecords.approver:id,name',
        ]);

        $company = [
            'name'    => $this->settings->get('company.name', 'Philippine Ogami Corporation'),
            'address' => $this->settings->get('company.address', ''),
            'tin'     => $this->settings->get('company.tin', ''),
        ];

        $pdf = Pdf::loadView('pdf.purchase-request', [
            'pr'        => $pr,
            'company'   => $company,
            'now'       => now(),
            'approvals' => ApprovalSignatureBuilder::for($pr, $pr->requester),
        ])->setPaper('a4', 'portrait');

        $filename = $pr->pr_number.'.pdf';
        return response($pdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
