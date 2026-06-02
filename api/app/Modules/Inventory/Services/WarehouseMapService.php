<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class WarehouseMapService
{
    /**
     * Get full warehouse tree with bin occupancy data for the visual map.
     */
    public function map(): Collection
    {
        return Warehouse::query()
            ->with([
                'zones' => fn ($q) => $q->orderBy('code'),
                'zones.locations' => fn ($q) => $q->orderBy('code')->with('currentItem'),
                'zones.locations.zone.warehouse',
            ])
            ->orderBy('name')
            ->get();
    }

    /**
     * Get bin occupancy summary for a specific location.
     */
    public function binDetail(int $locationId): ?array
    {
        $loc = WarehouseLocation::with(['zone.warehouse', 'currentItem'])->find($locationId);
        if (!$loc) return null;

        // Get stock levels at this location
        $stockLevels = StockLevel::query()
            ->where('location_id', $locationId)
            ->with('item')
            ->get();

        // Get last movement to/from this location
        $lastMovement = StockMovement::query()
            ->where(function ($q) use ($locationId) {
                $q->where('from_location_id', $locationId)
                  ->orWhere('to_location_id', $locationId);
            })
            ->latest()
            ->first();

        return [
            'location' => [
                'id'              => $loc->id,
                'code'            => $loc->code,
                'full_code'       => $loc->full_code,
                'rack'            => $loc->rack,
                'bin'             => $loc->bin,
                'is_blocked'      => $loc->is_blocked,
                'blocked_reason'  => $loc->blocked_reason,
                'capacity_kg'     => $loc->capacity_kg,
                'current_item'    => $loc->current_item_id ? [
                    'id'   => $loc->currentItem?->id,
                    'code' => $loc->currentItem?->code,
                    'name' => $loc->currentItem?->name,
                ] : null,
                'current_quantity'   => $loc->current_quantity,
                'current_lot_number' => $loc->current_lot_number,
                'zone' => [
                    'id'   => $loc->zone?->id,
                    'code' => $loc->zone?->code,
                    'name' => $loc->zone?->name,
                    'zone_type' => $loc->zone?->zone_type?->value,
                    'warehouse' => $loc->zone?->warehouse ? [
                        'id'   => $loc->zone->warehouse->id,
                        'code' => $loc->zone->warehouse->code,
                        'name' => $loc->zone->warehouse->name,
                    ] : null,
                ],
            ],
            'stock_levels' => $stockLevels->map(fn ($sl) => [
                'item_id'     => $sl->item_id,
                'item_code'   => $sl->item?->code,
                'item_name'   => $sl->item?->name,
                'quantity'    => $sl->quantity,
                'reserved'    => $sl->reserved_quantity,
                'available'   => $sl->available,
                'unit_cost'   => $sl->weighted_avg_cost,
                'total_value' => $sl->total_value,
            ]),
            'last_movement' => $lastMovement ? [
                'id'             => $lastMovement->id,
                'movement_type'  => $lastMovement->movement_type->value,
                'item_code'      => $lastMovement->item?->code,
                'quantity'       => $lastMovement->quantity,
                'direction'      => $lastMovement->to_location_id === $locationId ? 'in' : 'out',
                'created_at'     => $lastMovement->created_at?->toISOString(),
            ] : null,
        ];
    }
}
