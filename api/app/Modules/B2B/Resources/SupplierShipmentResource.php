<?php

declare(strict_types=1);

namespace App\Modules\B2B\Resources;

use App\Modules\B2B\Policies\SupplierPoCapabilities;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $deadline = SupplierPoCapabilities::shipmentDeadline($this->resource);

        return [
            'id' => $this->hash_id,
            'return_rma_number' => $this->return_request_id ? $this->returnRequest?->rma_number : null,
            'quantity_recorded' => $this->items->isNotEmpty(),
            'can_edit' => ! (bool) ($this->resource->getAttribute('received_grn_exists')
                ?? $this->goodsReceiptNotes()->whereNotNull('received_date')->exists()),
            'items' => $this->items->map(static fn ($line): array => [
                'purchase_order_item_id' => $line->purchaseOrderItem?->hash_id,
                'description' => $line->purchaseOrderItem?->description,
                'quantity_shipped' => (string) $line->quantity_shipped,
            ])->values()->all(),
            'shipped_date' => $this->shipped_date?->toDateString(),
            'carrier' => $this->carrier,
            'tracking_number' => $this->tracking_number,
            'estimated_arrival' => $this->estimated_arrival?->toDateString(),
            'late_eta' => (bool) ($deadline
                && $this->estimated_arrival
                && $this->estimated_arrival->toDateString() > $deadline->toDateString()),
            'notes' => $this->notes,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
