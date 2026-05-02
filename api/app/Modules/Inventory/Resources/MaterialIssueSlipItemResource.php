<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialIssueSlipItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'item'            => $this->whenLoaded('item', fn () => [
                'id'              => $this->item->hash_id,
                'code'            => $this->item->code,
                'name'            => $this->item->name,
                'unit_of_measure' => $this->item->unit_of_measure,
            ]),
            'location'        => $this->whenLoaded('location', fn () => [
                'id'   => $this->location->hash_id,
                'code' => $this->location->code,
            ]),
            'quantity_issued' => (string) $this->quantity_issued,
            'unit_cost'       => (string) $this->unit_cost,
            'total_cost'      => (string) $this->total_cost,
            'remarks'         => $this->remarks,
        ];
    }
}
