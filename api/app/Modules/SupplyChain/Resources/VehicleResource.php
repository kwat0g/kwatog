<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->hash_id,
            'plate_number'  => $this->plate_number,
            'name'          => $this->name,
            'vehicle_type'  => $this->vehicle_type,
            'capacity_kg'   => $this->capacity_kg !== null ? (float) $this->capacity_kg : null,
            'status'        => $this->status,
            'notes'         => $this->notes,
        ];
    }
}
