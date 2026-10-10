<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'work_order' => $this->workOrder ? ['id' => $this->workOrder->hash_id, 'wo_number' => $this->workOrder->wo_number] : null,
            'item' => $this->item ? ['id' => $this->item->hash_id, 'code' => $this->item->code, 'name' => $this->item->name, 'unit_of_measure' => $this->item->unit_of_measure] : null,
            'location' => $this->location ? ['id' => $this->location->hash_id, 'full_code' => $this->location->full_code] : null,
            'quantity' => (string) $this->quantity,
            'status' => $this->status->value,
            'reserved_at' => $this->reserved_at?->toIso8601String(),
            'ended_at' => $this->released_at?->toIso8601String(),
        ];
    }
}
