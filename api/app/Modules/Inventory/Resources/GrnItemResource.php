<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GrnItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'purchase_order_item_id' => $this->purchase_order_item_id,
            'item'                   => $this->whenLoaded('item', fn () => [
                'id'              => $this->item->hash_id,
                'code'            => $this->item->code,
                'name'            => $this->item->name,
                'unit_of_measure' => $this->item->unit_of_measure,
            ]),
            'location'               => $this->whenLoaded('location', fn () => [
                'id'        => $this->location->hash_id,
                'code'      => $this->location->code,
                'full_code' => $this->location->full_code,
            ]),
            'quantity_received' => (string) $this->quantity_received,
            'quantity_accepted' => (string) $this->quantity_accepted,
            'unit_cost'         => (string) $this->unit_cost,
            'remarks'           => $this->remarks,
        ];
    }
}
