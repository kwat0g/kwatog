<?php

declare(strict_types=1);

namespace App\Modules\B2B\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'item' => $this->whenLoaded('item', fn () => [
                'id' => $this->item->hash_id,
                'code' => $this->item->code,
                'name' => $this->item->name,
                'unit_of_measure' => $this->item->unit_of_measure,
            ]),
            'supplier_item_code' => $this->supplier_item_code,
            'supplier_item_name' => $this->supplier_item_name,
            'price' => (string) $this->price,
            'order_uom' => $this->order_uom,
            'base_qty_per_order_unit' => $this->base_qty_per_order_unit !== null ? (string) $this->base_qty_per_order_unit : null,
            'lead_time_days' => (int) $this->lead_time_days,
            'valid_until' => optional($this->valid_until)?->format('Y-m-d'),
            'status' => $this->status->value,
            'rejection_reason' => $this->rejection_reason,
            'submitted_at' => optional($this->submitted_at)->toIso8601String(),
            'reviewed_at' => optional($this->reviewed_at)?->toIso8601String(),
        ];
    }
}
