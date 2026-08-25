<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Services;

use App\Common\Enums\DocumentType;
use App\Common\Services\DocumentVaultService;
use App\Common\Services\Pdf\PdfRenderService;
use App\Modules\Auth\Models\User;
use App\Modules\SupplyChain\Models\Shipment;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
    public function __construct(
        private readonly PdfRenderService $renderer,
        private readonly DocumentVaultService $vault,
    ) {}

    /**
     * Packing list: shipper, consignee, vessel, container(s), items, quantities, weights.
     */
    public function generatePackingList(Shipment $shipment): StreamedResponse
    {
        $shipment->loadMissing([
            'purchaseOrder.vendor',
            'purchaseOrder.items.item',
            'containers',
        ]);

        $bytes = $this->renderer->render('pdf.packing-list', [
            'shipment'   => $shipment,
            'po'         => $shipment->purchaseOrder,
            'vendor'     => $shipment->purchaseOrder?->vendor,
            'containers' => $shipment->containers,
            'items'      => $shipment->purchaseOrder?->items ?? collect(),
            'now'        => now(),
        ], ['orientation' => 'portrait', 'title' => DocumentType::PackingList->label()]);
        return $this->storeAndStream($bytes, DocumentType::PackingList, $shipment);
    }

    /**
     * Commercial invoice: same header + unit prices, totals, payment terms, incoterms.
     */
    public function generateCommercialInvoice(Shipment $shipment): StreamedResponse
    {
        $shipment->loadMissing([
            'purchaseOrder.vendor',
            'purchaseOrder.items.item',
            'containers',
        ]);

        $po = $shipment->purchaseOrder;

        $bytes = $this->renderer->render('pdf.commercial-invoice', [
            'shipment'   => $shipment,
            'po'         => $po,
            'vendor'     => $po?->vendor,
            'containers' => $shipment->containers,
            'items'      => $po?->items ?? collect(),
            'now'        => now(),
        ], ['orientation' => 'portrait', 'title' => DocumentType::CommercialInvoice->label()]);
        return $this->storeAndStream($bytes, DocumentType::CommercialInvoice, $shipment);
    }

    private function storeAndStream(string $bytes, DocumentType $type, Shipment $shipment): StreamedResponse
    {
        $actor = auth()->user();
        $user = $actor instanceof User ? $actor : null;
        $document = $this->vault->store($bytes, $type, $shipment, $user);

        return $this->vault->streamInline($document);
    }
}
