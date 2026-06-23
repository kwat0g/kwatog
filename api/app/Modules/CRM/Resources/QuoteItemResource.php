<?php

declare(strict_types=1);

namespace App\Modules\CRM\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuoteItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->hash_id,
            'quantity'    => (string) $this->quantity,
            'unit_price'  => (string) $this->unit_price,
            'line_total'  => (string) $this->line_total,
            'description' => $this->description,
            'product'     => $this->whenLoaded('product', fn () => $this->product ? [
                'id'              => $this->product->hash_id,
                'part_number'     => $this->product->part_number,
                'name'            => $this->product->name,
                'unit_of_measure' => $this->product->unit_of_measure,
            ] : null),
        ];
    }
}
