<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Controllers;

use App\Modules\SupplyChain\Models\Shipment;
use App\Modules\SupplyChain\Services\ImpexDocumentService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Download endpoints for ImpEx customs documents — packing list
 * and commercial invoice PDFs auto-generated from shipment + PO data.
 */
class ImpexDocumentController
{
    public function __construct(private readonly ImpexDocumentService $service) {}

    public function packingList(Shipment $shipment): Response
    {
        return $this->service->generatePackingList($shipment);
    }

    public function commercialInvoice(Shipment $shipment): Response
    {
        return $this->service->generateCommercialInvoice($shipment);
    }
}
