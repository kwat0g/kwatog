<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentLandedCostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->hash_id,
            'shipment_id'         => $this->shipment?->hash_id,
            'purchase_order_item' => $this->whenLoaded('purchaseOrderItem', fn () => $this->purchaseOrderItem ? [
                'id'          => $this->purchaseOrderItem->hash_id,
                'description' => $this->purchaseOrderItem->description,
                'quantity'    => $this->purchaseOrderItem->quantity,
                'unit_price'  => $this->purchaseOrderItem->unit_price,
                'total'       => $this->purchaseOrderItem->total,
            ] : null),
            'allocated_freight'   => $this->allocated_freight,
            'allocated_insurance' => $this->allocated_insurance,
            'allocated_duties'    => $this->allocated_duties,
            'allocated_brokerage' => $this->allocated_brokerage,
            'allocated_other'     => $this->allocated_other,
            'total_allocated'     => $this->total_allocated,
        ];
    }
}
