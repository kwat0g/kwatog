<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Services;

use App\Common\Services\SettingsService;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

class PurchaseOrderPdfService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function render(PurchaseOrder $po): Response
    {
        $po->loadMissing(['vendor', 'items.item', 'creator', 'approver']);
        $company = [
            'name'    => $this->settings->get('company.name', 'Philippine Ogami Corporation'),
            'address' => $this->settings->get('company.address', ''),
            'tin'     => $this->settings->get('company.tin', ''),
        ];
        $pdf = Pdf::loadView('pdf.purchase-order', [
            'po'      => $po,
            'company' => $company,
            'now'     => now(),
        ])->setPaper('a4', 'portrait');
        $filename = $po->po_number.'.pdf';
        return response($pdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
