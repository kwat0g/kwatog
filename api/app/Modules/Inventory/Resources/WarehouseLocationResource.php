<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $zoneLoaded = $this->relationLoaded('zone') && $this->zone;
        $whLoaded   = $zoneLoaded && $this->zone->relationLoaded('warehouse') && $this->zone->warehouse;

        return [
            'id'        => $this->hash_id,
            'zone_id'   => $zoneLoaded ? $this->zone->hash_id : null,
            'code'      => $this->code,
            'rack'      => $this->rack,
            'bin'       => $this->bin,
            'is_active' => (bool) $this->is_active,
            'full_code' => $this->full_code,
            'zone'      => $this->whenLoaded('zone', fn () => $this->zone ? [
                'id'        => $this->zone->hash_id,
                'name'      => $this->zone->name,
                'code'      => $this->zone->code,
                'zone_type' => (string) $this->zone->zone_type?->value,
                'warehouse' => $whLoaded ? [
                    'id'   => $this->zone->warehouse->hash_id,
                    'name' => $this->zone->warehouse->name,
                    'code' => $this->zone->warehouse->code,
                ] : null,
            ] : null),
        ];
    }
}
