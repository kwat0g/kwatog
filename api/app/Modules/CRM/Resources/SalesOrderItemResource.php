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
                'id'              => $this->product->hash_id,
                'part_number'     => $this->product->part_number,
                'name'            => $this->product->name,
                'unit_of_measure' => $this->product->unit_of_measure,
            ]),
            'quantity'           => (string) $this->quantity,
            'unit_price'         => (string) $this->unit_price,
            'total'              => (string) $this->total,
            'quantity_delivered' => (string) $this->quantity_delivered,
            'remaining_quantity' => $this->remaining_quantity,
            'delivery_date'      => optional($this->delivery_date)->toDateString(),
        ];
    }
}
