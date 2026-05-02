<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->hash_id,
            'created_at'     => optional($this->created_at)->toIso8601String(),
            'movement_type'  => (string) $this->movement_type?->value,
            'item'           => $this->whenLoaded('item', fn () => [
                'id'   => $this->item->hash_id,
                'code' => $this->item->code,
                'name' => $this->item->name,
            ]),
            'from_location'  => $this->whenLoaded('fromLocation', fn () => $this->fromLocation ? [
                'id'   => $this->fromLocation->hash_id,
                'code' => $this->fromLocation->code,
            ] : null),
            'to_location'    => $this->whenLoaded('toLocation', fn () => $this->toLocation ? [
                'id'   => $this->toLocation->hash_id,
                'code' => $this->toLocation->code,
            ] : null),
            'quantity'       => (string) $this->quantity,
            'unit_cost'      => (string) $this->unit_cost,
            'total_cost'     => (string) $this->total_cost,
            'reference_type' => $this->reference_type,
            'reference_id'   => $this->reference_id,
            'remarks'        => $this->remarks,
            'creator'        => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id'   => $this->creator->hash_id,
                'name' => $this->creator->name,
            ] : null),
        ];
    }
}
