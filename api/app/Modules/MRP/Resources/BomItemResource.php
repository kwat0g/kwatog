<?php

declare(strict_types=1);

namespace App\Modules\MRP\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BomItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->hash_id,
            'item'               => $this->whenLoaded('item', fn () => $this->item ? [
                'id'              => $this->item->hash_id,
                'code'            => $this->item->code,
                'name'            => $this->item->name,
                'unit_of_measure' => $this->item->unit_of_measure,
                'item_type'       => (string) $this->item->item_type?->value,
            ] : null),
            'quantity_per_unit'  => (string) $this->quantity_per_unit,
            'unit'               => $this->unit,
            'waste_factor'       => (string) $this->waste_factor,
            'effective_quantity' => $this->effective_quantity,
            'sort_order'         => (int) $this->sort_order,
        ];
    }
}
