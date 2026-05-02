<?php

declare(strict_types=1);

namespace App\Modules\MRP\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->hash_id,
            'product'    => $this->whenLoaded('product', fn () => [
                'id'              => $this->product->hash_id,
                'part_number'     => $this->product->part_number,
                'name'            => $this->product->name,
                'unit_of_measure' => $this->product->unit_of_measure,
            ]),
            'version'    => (int) $this->version,
            'is_active'  => (bool) $this->is_active,
            'item_count' => (int) ($this->items_count ?? $this->items?->count() ?? 0),
            'items'      => $this->whenLoaded('items', fn () => BomItemResource::collection($this->items)),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
