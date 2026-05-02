<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseRequestItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'item'                 => $this->whenLoaded('item', fn () => $this->item ? [
                'id'              => $this->item->hash_id,
                'code'            => $this->item->code,
                'name'            => $this->item->name,
                'unit_of_measure' => $this->item->unit_of_measure,
            ] : null),
            'description'          => $this->description,
            'quantity'             => (string) $this->quantity,
            'unit'                 => $this->unit,
            'estimated_unit_price' => $this->estimated_unit_price ? (string) $this->estimated_unit_price : null,
            'estimated_total'      => $this->estimated_total,
            'purpose'              => $this->purpose,
        ];
    }
}
