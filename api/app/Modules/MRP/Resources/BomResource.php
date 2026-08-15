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
            'cost_batch_size' => $this->cost_batch_size !== null ? (string) $this->cost_batch_size : '1.000',
            'product'    => $this->whenLoaded('product', fn () => [
                'id'              => $this->product->hash_id,
                'part_number'     => $this->product->part_number,
                'name'            => $this->product->name,
                'unit_of_measure' => $this->product->unit_of_measure,
            ]),
            'version'    => (int) $this->version,
            'is_active'  => (bool) $this->is_active,
            'item_count' => (int) ($this->items_count ?? $this->items?->count() ?? 0),
            'material_cost' => $this->material_cost !== null ? (string) $this->material_cost : null,
            'labor_cost' => $this->labor_cost !== null ? (string) $this->labor_cost : null,
            'machine_cost' => $this->machine_cost !== null ? (string) $this->machine_cost : null,
            'overhead_cost' => $this->overhead_cost !== null ? (string) $this->overhead_cost : null,
            'total_cost' => $this->total_cost !== null ? (string) $this->total_cost : null,
            'cost_basis' => $this->cost_basis,
            'costed_at' => optional($this->costed_at)->toIso8601String(),
            'cost_warnings' => $this->cost_warnings ?? [],
            'items'      => $this->whenLoaded('items', fn () => BomItemResource::collection($this->items)),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'deleted_at' => optional($this->deleted_at)?->toIso8601String(),
        ];
    }
}
