<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RequestForQuoteItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id, 'description' => $this->description, 'specification' => $this->specification,
            'quantity' => (string) $this->quantity, 'unit' => $this->unit,
            'required_delivery_date' => optional($this->required_delivery_date)->toDateString(),
            'allow_partial_quantity' => (bool) $this->allow_partial_quantity, 'allow_substitute' => (bool) $this->allow_substitute,
            'item' => $this->whenLoaded('item', fn () => $this->item ? ['id' => $this->item->hash_id, 'code' => $this->item->code, 'name' => $this->item->name, 'unit_of_measure' => $this->item->unit_of_measure] : null),
        ];
    }
}
