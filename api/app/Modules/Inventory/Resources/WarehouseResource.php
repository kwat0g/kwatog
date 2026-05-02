<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->hash_id,
            'name'      => $this->name,
            'code'      => $this->code,
            'address'   => $this->address,
            'is_active' => (bool) $this->is_active,
            'zones'     => WarehouseZoneResource::collection($this->whenLoaded('zones')),
        ];
    }
}
