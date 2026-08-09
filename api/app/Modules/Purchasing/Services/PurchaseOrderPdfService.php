<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Services\SettingsService;
use App\Common\Support\ApprovalSignatureBuilder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

class PurchaseOrderPdfService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function render(PurchaseOrder $po): Response
    {
        $po->loadMissing([
            'vendor',
            'items.item',
            'creator',
            'approver',
            // Sprint P9 — for the 4-tier signature block.
            'approvalRecords.approver:id,name',
        ]);
        $company = [
            'name'    => $this->setting('company.legal_name'),
            'address' => $this->setting('company.address'),
            'tin'     => $this->setting('company.tin'),
        ];
        $pdf = Pdf::loadView('pdf.purchase-order', [
            'po'        => $po,
            'company'   => $company,
            'now'       => now(),
            // Sprint P9 — drives the new signature-block partial.
            'approvals' => ApprovalSignatureBuilder::for($po, $po->creator),
        ])->setPaper('a4', 'portrait');
        $filename = $po->po_number.'.pdf';
        return response($pdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    private function setting(string $key): string
    {
        try {
            $val = $this->settings->get($key);
            return is_string($val) && trim($val) !== '' ? $val : '';
        } catch (\Throwable) {
            return '';
        }
    }
}
