<?php

declare(strict_types=1);

namespace App\Modules\Production\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductRoutingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->hash_id,
            'product'          => $this->whenLoaded('product', fn () => $this->product ? [
                'id'          => $this->product->hash_id,
                'part_number' => $this->product->part_number,
                'name'        => $this->product->name,
            ] : null),
            'version'          => (int) $this->version,
            'is_active'        => (bool) $this->is_active,
            'total_cycle_time' => $this->total_cycle_time,
            'notes'            => $this->notes,
            'operations'       => RoutingOperationResource::collection($this->whenLoaded('operations')),
            'created_at'       => optional($this->created_at)->toIso8601String(),
            'updated_at'       => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
