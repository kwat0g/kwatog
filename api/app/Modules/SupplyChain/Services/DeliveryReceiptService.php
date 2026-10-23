<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Services;

use App\Common\Enums\DocumentType;
use App\Common\Services\DocumentVaultService;
use App\Common\Services\Pdf\PdfRenderService;
use App\Modules\Auth\Models\User;
use App\Modules\SupplyChain\Models\Delivery;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Chain 1 (Order to Cash) — the signed-paper counterpart of the driver's phone.
 *
 * Prints where the goods go and who signs for them. The address comes from
 * Delivery::resolvedDeliveryAddress() (snapshot → customer ship-to → customer
 * billing address) rather than being re-derived here, so the receipt, the
 * office delivery page and the driver run sheet can never disagree.
 *
 * Vaulted like every other generated PDF: the bytes are auditable and the
 * document shows up against the delivery it belongs to.
 */
class DeliveryReceiptService
{
    public function __construct(
        private readonly PdfRenderService $renderer,
        private readonly DocumentVaultService $vault,
    ) {}

    public function render(Delivery $delivery): StreamedResponse
    {
        $bytes = $this->renderer->render('pdf.delivery-receipt', $this->viewData($delivery), [
            'orientation' => 'portrait',
            'title' => DocumentType::DeliveryReceipt->label(),
        ]);

        $actor = auth()->user();
        $document = $this->vault->store(
            $bytes,
            DocumentType::DeliveryReceipt,
            $delivery,
            $actor instanceof User ? $actor : null,
        );

        return $this->vault->streamInline($document);
    }

    /**
     * The receipt's content, separated from the PDF wrapper.
     *
     * Public so the printed sheet can be asserted as HTML — a PDF is compressed
     * bytes, and "the file rendered" would keep passing if the address silently
     * stopped reaching the template. Everything the address depends on (the
     * snapshot, the fallback order) is resolved here, not in the Blade.
     *
     * @return array<string, mixed>
     */
    public function viewData(Delivery $delivery): array
    {
        $delivery->loadMissing([
            'salesOrder:id,so_number,customer_id',
            'salesOrder.customer:id,name,address,contact_person,phone,delivery_address,delivery_contact_person,delivery_contact_phone',
            'vehicle:id,plate_number,name',
            'driver:id,name',
            'items.salesOrderItem.product:id,part_number,name,unit_of_measure',
        ]);

        return [
            'delivery' => $delivery,
            'customer' => $delivery->salesOrder?->customer,
            'items' => $delivery->items,
            // Resolved once, on the model. Not re-expressed in this view.
            'address' => $delivery->resolvedDeliveryAddress(),
            'contactPerson' => $delivery->resolvedDeliveryContactPerson(),
            'contactPhone' => $delivery->resolvedDeliveryContactPhone(),
            'now' => now(),
        ];
    }
}
