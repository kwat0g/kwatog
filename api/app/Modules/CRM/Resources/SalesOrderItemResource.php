<?php

declare(strict_types=1);

namespace App\Modules\CRM\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->hash_id,
            'product'            => $this->whenLoaded('product', fn () => [
                'id'              => $this->product?->hash_id,
                'part_number'     => $this->product?->part_number,
                'name'            => $this->product?->name,
                'unit_of_measure' => $this->product?->unit_of_measure,
            ]),
            'fulfillment_policy' => $this->fulfillment_policy ?? 'produce_new',
            'quantity'           => (string) $this->quantity,
            'unit_price'         => (string) $this->unit_price,
            'price_source' => $this->price_source,
            'price_agreement_id' => $this->price_agreement_id ? app('hashids')->encode($this->price_agreement_id) : null,
            'pricing_date' => optional($this->pricing_date)->toDateString(),
            'price_tier_min_qty' => $this->price_tier_min_qty,
            'total'              => (string) $this->total,
            'quantity_delivered' => (string) $this->quantity_delivered,
            'remaining_quantity' => $this->remaining_quantity,
            'delivery_date'      => optional($this->delivery_date)->toDateString(),
            'requested_delivery_date' => optional($this->requested_delivery_date ?? $this->delivery_date)->toDateString(),
            'warranty_terms_snapshot' => $this->warranty_terms_snapshot,
        ];
    }
}
