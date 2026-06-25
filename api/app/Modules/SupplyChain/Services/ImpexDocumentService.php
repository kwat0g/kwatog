<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Services;

use App\Common\Services\SettingsService;
use App\Modules\SupplyChain\Models\Shipment;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generates packing list and commercial invoice PDFs for inbound
 * resin shipments — required for Philippine customs clearance.
 *
 * Mirrors PurchaseOrderPdfService / PurchaseRequestPdfService patterns:
 *   - SettingsService for company branding
 *   - loadView() + setPaper() + output() → inline Response
 */
class ImpexDocumentService
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Packing list: shipper, consignee, vessel, container(s), items, quantities, weights.
     */
    public function generatePackingList(Shipment $shipment): Response
    {
        $shipment->loadMissing([
            'purchaseOrder.vendor',
            'purchaseOrder.items.item',
            'containers',
        ]);

        $pdf = Pdf::loadView('pdf.packing-list', [
            'shipment'   => $shipment,
            'company'    => $this->companyInfo(),
            'po'         => $shipment->purchaseOrder,
            'vendor'     => $shipment->purchaseOrder?->vendor,
            'containers' => $shipment->containers,
            'items'      => $shipment->purchaseOrder?->items ?? collect(),
            'now'        => now(),
        ])->setPaper('a4', 'portrait');

        $filename = "packing-list-{$shipment->shipment_number}.pdf";

        return response($pdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    /**
     * Commercial invoice: same header + unit prices, totals, payment terms, incoterms.
     */
    public function generateCommercialInvoice(Shipment $shipment): Response
    {
        $shipment->loadMissing([
            'purchaseOrder.vendor',
            'purchaseOrder.items.item',
            'containers',
        ]);

        $po = $shipment->purchaseOrder;

        $pdf = Pdf::loadView('pdf.commercial-invoice', [
            'shipment'   => $shipment,
            'company'    => $this->companyInfo(),
            'po'         => $po,
            'vendor'     => $po?->vendor,
            'containers' => $shipment->containers,
            'items'      => $po?->items ?? collect(),
            'now'        => now(),
        ])->setPaper('a4', 'portrait');

        $filename = "commercial-invoice-{$shipment->shipment_number}.pdf";

        return response($pdf->output(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    /**
     * @return array{name: string, address: string, tin: string}
     */
    private function companyInfo(): array
    {
        return [
            'name'    => (string) $this->settings->get('company.name', 'Philippine Ogami Corporation'),
            'address' => (string) $this->settings->get('company.address', 'FCIE, Dasmariñas, Cavite'),
            'tin'     => (string) $this->settings->get('company.tin', ''),
        ];
    }
}
