<?php

declare(strict_types=1);

namespace App\Modules\Quality\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InspectionSpecResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->hash_id,
            'version'    => (int) $this->version,
            'is_active'  => (bool) $this->is_active,
            'notes'      => $this->notes,
            'item_count' => (int) ($this->items_count ?? $this->items?->count() ?? 0),
            'product'    => $this->whenLoaded('product', fn () => $this->product ? [
                'id'          => $this->product->hash_id,
                'part_number' => $this->product->part_number,
                'name'        => $this->product->name,
            ] : null),
            'creator'    => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id'   => $this->creator->hash_id,
                'name' => $this->creator->name,
            ] : null),
            'items'      => $this->whenLoaded('items', fn () =>
                $this->items->map(fn ($it) => [
                    'id'              => $it->hash_id,
                    'parameter_name'  => $it->parameter_name,
                    'parameter_type'  => (string) ($it->parameter_type?->value ?? $it->parameter_type),
                    'unit_of_measure' => $it->unit_of_measure,
                    'nominal_value'   => $it->nominal_value !== null ? (string) $it->nominal_value : null,
                    'tolerance_min'   => $it->tolerance_min  !== null ? (string) $it->tolerance_min  : null,
                    'tolerance_max'   => $it->tolerance_max  !== null ? (string) $it->tolerance_max  : null,
                    'is_critical'     => (bool) $it->is_critical,
                    'sort_order'      => (int) $it->sort_order,
                    'notes'           => $it->notes,
                ])->values()
            ),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
