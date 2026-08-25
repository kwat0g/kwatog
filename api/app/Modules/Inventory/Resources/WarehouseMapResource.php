<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use App\Modules\Inventory\Services\StockLocationSummaryService;
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
                            'current_item'    => $this->summary($loc)['current_item'] ? [
                                'id'       => $this->summary($loc)['current_item']->hash_id,
                                'code'     => $this->summary($loc)['current_item']->code,
                                'name'     => $this->summary($loc)['current_item']->name,
                            ] : null,
                            'current_quantity'    => $this->summary($loc)['current_quantity'],
                            'current_lot_number'  => $this->summary($loc)['current_lot_number'],
                            'current_expiry_date' => $this->summary($loc)['current_expiry_date'],
                            'stock_status'        => $this->getStockStatus($loc),
                            'stock_status_label'  => $this->getStockStatusLabel($loc),
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

    private function getStockStatusLabel($loc): string
    {
        return match ($this->getStockStatus($loc)) {
            'empty' => 'Empty',
            'ok' => 'Stocked',
            'low' => 'Low',
            'full' => 'Full',
            'blocked' => 'Blocked',
        };
    }

    private function getStockQuantity($loc): float|string
    {
        return $this->summary($loc)['total_quantity'];
    }

    /** @return array{current_item: mixed, current_quantity: string, current_lot_number: ?string, current_expiry_date: ?string, total_quantity: string} */
    private function summary($loc): array
    {
        $summary = $loc->getAttribute('inventory_summary');
        if (is_array($summary)) {
            return $summary;
        }

        $levels = $loc->relationLoaded('stockLevels')
            ? $loc->stockLevels
            : $loc->stockLevels()->with('item')->get();

        return app(StockLocationSummaryService::class)->summarize($levels);
    }
}
