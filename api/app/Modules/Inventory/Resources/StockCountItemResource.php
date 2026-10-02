<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockCountItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'session_id' => $this->session_id ? app('hashids')->encode((int) $this->session_id) : null,
            'location' => $this->whenLoaded('location', fn () => [
                'id' => $this->location?->hash_id,
                'code' => $this->location?->code,
                'full_code' => $this->location?->full_code,
            ]),
            'item' => $this->whenLoaded('item', fn () => [
                'id' => $this->item?->hash_id,
                'code' => $this->item?->code,
                'name' => $this->item?->name,
                'unit_of_measure' => $this->item?->unit_of_measure,
            ]),
            'system_quantity' => $this->system_quantity,
            'counted_quantity' => $this->counted_quantity,
            'variance' => $this->variance,
            'variance_percent' => $this->variance_percent,
            'lot_number' => $this->lot_number,
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'counted_by' => $this->whenLoaded('counter', fn () => $this->counter ? [
                'id' => $this->counter->hash_id,
                'name' => $this->counter->name,
            ] : null),
            'counted_at' => $this->counted_at?->toISOString(),
            'verified_by' => $this->whenLoaded('verifier', fn () => $this->verifier ? [
                'id' => $this->verifier->hash_id,
                'name' => $this->verifier->name,
            ] : null),
            'notes' => $this->notes,
        ];
    }
}
