<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseMapResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->hash_id,
            'code'        => $this->code,
            'name'        => $this->name,
            'address'     => $this->address,
            'zones'       => $this->whenLoaded('zones', fn () =>
                $this->zones->map(fn ($zone) => [
                    'id'        => $zone->hash_id,
                    'code'      => $zone->code,
                    'name'      => $zone->name,
                    'zone_type' => $zone->zone_type?->value,
                    'type_label' => $zone->zone_type?->label(),
                    'locations' => $zone->relationLoaded('locations')
                        ? $zone->locations->map(fn ($loc) => [
                            'id'              => $loc->hash_id,
                            'code'            => $loc->code,
                            'full_code'       => $loc->full_code,
                            'rack'            => $loc->rack,
                            'bin'             => $loc->bin,
                            'is_blocked'      => $loc->is_blocked,
                            'blocked_reason'  => $loc->blocked_reason,
                            'capacity_kg'     => $loc->capacity_kg,
                            'current_item'    => $loc->current_item_id ? [
                                'id'       => $loc->currentItem?->hash_id,
                                'code'     => $loc->currentItem?->code,
                                'name'     => $loc->currentItem?->name,
                            ] : null,
                            'current_quantity'    => $loc->current_quantity,
                            'current_lot_number'  => $loc->current_lot_number,
                            'stock_status'        => $this->getStockStatus($loc),
                            'stock_quantity'      => $this->getStockQuantity($loc),
                            'last_movement_at'    => $loc->last_movement_at,
                        ])->values()
                        : [],
                ])
            ),
        ];
    }

    private function getStockStatus($loc): string
    {
        if ($loc->is_blocked) return 'blocked';
        $qty = (float) $this->getStockQuantity($loc);
        if ($qty <= 0) return 'empty';
        if ($loc->capacity_kg && $qty < $loc->capacity_kg * 0.2) return 'low';
        if ($loc->capacity_kg && $qty >= $loc->capacity_kg * 0.9) return 'full';
        return 'ok';
    }

    private function getStockQuantity($loc): float|string
    {
        // Use current_quantity from enhanced warehouse_locations
        return $loc->current_quantity ?? 0;
    }
}
