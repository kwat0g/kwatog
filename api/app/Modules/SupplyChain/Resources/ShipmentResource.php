<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                       => $this->hash_id,
            'shipment_number'          => $this->shipment_number,
            'status'                   => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'carrier'                  => $this->carrier,
            'vessel'                   => $this->vessel,
            'container_number'         => $this->container_number,
            'bl_number'                => $this->bl_number,
            'etd'                      => optional($this->etd)?->toDateString(),
            'atd'                      => optional($this->atd)?->toDateString(),
            'eta'                      => optional($this->eta)?->toDateString(),
            'ata'                      => optional($this->ata)?->toDateString(),
            'customs_clearance_date'   => optional($this->customs_clearance_date)?->toDateString(),
            'notes'                    => $this->notes,
            'purchase_order'           => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder ? [
                'id'        => $this->purchaseOrder->hash_id,
                'po_number' => $this->purchaseOrder->po_number,
            ] : null),
            'creator'                  => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id'   => $this->creator->hash_id,
                'name' => $this->creator->name,
            ] : null),
            'documents'                => $this->whenLoaded('documents', fn () =>
                ShipmentDocumentResource::collection($this->documents)->resolve()),
            'created_at'               => optional($this->created_at)?->toISOString(),
            'updated_at'               => optional($this->updated_at)?->toISOString(),
        ];
    }
}
