<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseOrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'purchase_request_item_id' => $this->purchase_request_item_id,
            'item'                     => $this->whenLoaded('item', fn () => [
                'id'              => $this->item->hash_id,
                'code'            => $this->item->code,
                'name'            => $this->item->name,
                'unit_of_measure' => $this->item->unit_of_measure,
            ]),
            'description'              => $this->description,
            'quantity'                 => (string) $this->quantity,
            'unit'                     => $this->unit,
            'unit_price'               => (string) $this->unit_price,
            'total'                    => (string) $this->total,
            'quantity_received'        => (string) $this->quantity_received,
            'quantity_remaining'       => $this->quantity_remaining,
        ];
    }
}
