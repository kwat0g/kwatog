<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApprovedSupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->hash_id,
            'item'           => $this->whenLoaded('item', fn () => [
                'id'   => $this->item->hash_id,
                'code' => $this->item->code,
                'name' => $this->item->name,
            ]),
            'vendor'         => $this->whenLoaded('vendor', fn () => [
                'id'   => $this->vendor->hash_id,
                'name' => $this->vendor->name,
            ]),
            'is_preferred'   => (bool) $this->is_preferred,
            'lead_time_days' => (int) $this->lead_time_days,
            'last_price'     => $this->last_price ? (string) $this->last_price : null,
            'last_price_at'  => optional($this->last_price_at)->toIso8601String(),
            'supplier_item_code'      => $this->supplier_item_code,
            'supplier_item_name'      => $this->supplier_item_name,
            'order_uom'               => $this->order_uom,
            'base_qty_per_order_unit' => $this->base_qty_per_order_unit !== null ? (string) $this->base_qty_per_order_unit : null,
            'price_valid_until'       => optional($this->price_valid_until)?->format('Y-m-d'),
            'supplier_managed'        => $this->supplier_listing_id !== null,
            'deleted_at'     => optional($this->deleted_at)?->toIso8601String(),
        ];
    }
}
