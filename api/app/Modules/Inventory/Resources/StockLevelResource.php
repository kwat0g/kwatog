<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockLevelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'item'              => $this->whenLoaded('item', fn () => [
                'id'   => $this->item->hash_id,
                'code' => $this->item->code,
                'name' => $this->item->name,
                'unit_of_measure' => $this->item->unit_of_measure,
            ]),
            'location'          => $this->whenLoaded('location', fn () => [
                'id'        => $this->location->hash_id,
                'code'      => $this->location->code,
                'full_code' => $this->location->full_code,
            ]),
            'quantity'          => (string) $this->quantity,
            'reserved_quantity' => (string) $this->reserved_quantity,
            'available'         => $this->available,
            'weighted_avg_cost' => (string) $this->weighted_avg_cost,
            'total_value'       => $this->total_value,
            'last_counted_at'   => optional($this->last_counted_at)->toIso8601String(),
        ];
    }
}
