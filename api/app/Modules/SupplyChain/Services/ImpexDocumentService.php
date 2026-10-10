<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Services;

use App\Common\Enums\DocumentType;
use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentVaultService;
use App\Common\Services\Pdf\PdfRenderService;
use App\Common\Support\Money;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\SupplyChain\Models\Shipment;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Generates internal packing-list and commercial-invoice drafts for inbound
 * shipments. Supplier-issued documents remain required for customs clearance.
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
            'shipment' => $shipment,
            'po' => $shipment->purchaseOrder,
            'vendor' => $shipment->purchaseOrder?->vendor,
            'containers' => $shipment->containers,
            'items' => $this->documentItems($shipment),
            'isDraft' => true,
            'now' => now(),
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
        $items = $this->documentItems($shipment);
        $subtotal = $items->reduce(fn (string $sum, object $item): string => Money::add($sum, (string) $item->total), Money::zero());

        $bytes = $this->renderer->render('pdf.commercial-invoice', [
            'shipment' => $shipment,
            'po' => $po,
            'vendor' => $po?->vendor,
            'containers' => $shipment->containers,
            'items' => $items,
            'subtotal' => $subtotal,
            'isDraft' => true,
            'now' => now(),
        ], ['orientation' => 'portrait', 'title' => DocumentType::CommercialInvoice->label()]);

        return $this->storeAndStream($bytes, DocumentType::CommercialInvoice, $shipment);
    }

    /** Internal drafts use recorded receipts; before receipt they show PO intent explicitly. */
    private function documentItems(Shipment $shipment): Collection
    {
        $receipts = GoodsReceiptNote::query()->where('shipment_id', $shipment->id)
            ->where('status', '!=', GrnStatus::Draft->value)->with('items.purchaseOrderItem.item')->get();
        if ($receipts->isEmpty()) {
            return $shipment->purchaseOrder?->items ?? collect();
        }

        return $receipts->flatMap->items->map(function ($received) use ($shipment): object {
            $line = $received->purchaseOrderItem;
            if ($line === null || $line->purchase_order_id !== $shipment->purchase_order_id) {
                throw new BusinessRuleException('Shipment receipt is missing matching purchase-order line identity.');
            }
            // Receipt quantity is in inventory base UoM. Convert it to the
            // purchase unit before multiplying by the agreed purchase price.
            $quantity = $line->purchaseQuantity((string) $received->quantity_received, 6);
            $unit = $line->unit;

            return (object) [
                'item' => $line->item,
                'description' => $line->description,
                'quantity' => $quantity,
                'unit' => $unit,
                'unit_price' => $line->unit_price,
                'total' => Money::round2(bcmul((string) $quantity, (string) $line->unit_price, 6)),
            ];
        })->values();
    }

    private function storeAndStream(string $bytes, DocumentType $type, Shipment $shipment): StreamedResponse
    {
        $actor = auth()->user();
        $user = $actor instanceof User ? $actor : null;
        $document = $this->vault->store($bytes, $type, $shipment, $user);

        return $this->vault->streamInline($document);
    }
}
