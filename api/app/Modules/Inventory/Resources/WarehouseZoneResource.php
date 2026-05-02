<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseZoneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->hash_id,
            'warehouse_id' => $this->whenLoaded('warehouse', fn () => $this->warehouse?->hash_id),
            'name'         => $this->name,
            'code'         => $this->code,
            'zone_type'    => (string) $this->zone_type?->value,
            'zone_type_label' => $this->zone_type?->label(),
            'locations'    => WarehouseLocationResource::collection($this->whenLoaded('locations')),
        ];
    }
}
