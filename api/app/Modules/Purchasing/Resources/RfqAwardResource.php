<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RfqAwardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $po = $this->relationLoaded('purchaseOrderItem') ? $this->purchaseOrderItem?->purchaseOrder : null;

        return [
            'id' => $this->hash_id,
            'awarded_quantity' => (string) $this->awarded_quantity,
            'awarded_unit_price' => (string) $this->awarded_unit_price,
            'awarded_total_delivered_cost' => (string) $this->awarded_total_delivered_cost,
            'award_reason' => $this->award_reason,
            'awarded_at' => optional($this->awarded_at)->toIso8601String(),
            'vendor' => $this->whenLoaded('vendor', fn () => $this->vendor ? ['id' => $this->vendor->hash_id, 'name' => $this->vendor->name] : null),
            'rfq_item' => $this->whenLoaded('rfqItem', fn () => $this->rfqItem ? ['id' => $this->rfqItem->hash_id, 'description' => $this->rfqItem->description] : null),
            'purchase_order' => $po ? ['id' => $po->hash_id, 'po_number' => $po->po_number] : null,
        ];
    }
}
