<?php

declare(strict_types=1);

namespace App\Modules\B2B\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'shipped_date' => $this->shipped_date?->toDateString(),
            'carrier' => $this->carrier,
            'tracking_number' => $this->tracking_number,
            'estimated_arrival' => $this->estimated_arrival?->toDateString(),
            'notes' => $this->notes,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
