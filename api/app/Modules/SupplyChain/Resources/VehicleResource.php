<?php

declare(strict_types=1);

namespace App\Modules\SupplyChain\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

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
            'status_label'  => Str::headline((string) $this->status),
            'notes'         => $this->notes,
            'deleted_at'    => optional($this->deleted_at)?->toIso8601String(),
        ];
    }
}
