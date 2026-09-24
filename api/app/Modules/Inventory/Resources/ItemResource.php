<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Callers MUST eager-load aggregates via withSum (see ItemService::list/show)
        // to satisfy Model::shouldBeStrict(). Fall back to a loaded relation only.
        // JsonResource proxies property reads to Model::getAttribute(), so
        // `$this->attributes` is always null here — read the raw array.
        $attributes = $this->resource->getAttributes();
        $onHand = array_key_exists('on_hand_quantity', $attributes)
            ? (string) ($attributes['on_hand_quantity'] ?? '0.000')
            : '0.000';
        $reserved = array_key_exists('reserved_quantity', $attributes)
            ? (string) ($attributes['reserved_quantity'] ?? '0.000')
            : '0.000';
        if (! array_key_exists('on_hand_quantity', $attributes) && $this->relationLoaded('stockLevels')) {
            foreach ($this->stockLevels as $level) {
                $onHand = bcadd($onHand, (string) $level->quantity, 3);
            }
        }
        if (! array_key_exists('reserved_quantity', $attributes) && $this->relationLoaded('stockLevels')) {
            foreach ($this->stockLevels as $level) {
                $reserved = bcadd($reserved, (string) $level->reserved_quantity, 3);
            }
        }
        $available = bcsub($onHand, $reserved, 3);
        if (bccomp($available, '0', 3) < 0) $available = '0.000';

        $reorder = (string) $this->reorder_point;
        $safety = (string) $this->safety_stock;
        $stockStatus = bccomp($available, $safety, 3) <= 0 ? 'critical'
            : (bccomp($available, $reorder, 3) <= 0 ? 'low' : 'ok');

        return [
            'id' => $this->hash_id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->whenLoaded('category', fn () => $this->category ? ['id' => $this->category->hash_id, 'name' => $this->category->name] : null
            ),
            'item_type' => (string) $this->item_type?->value,
            'item_type_label' => $this->item_type?->label(),
            'unit_of_measure' => $this->unit_of_measure,
            'standard_cost' => (string) $this->standard_cost,
            'reorder_method' => (string) $this->reorder_method?->value,
            'reorder_point' => (string) $this->reorder_point,
            'safety_stock' => (string) $this->safety_stock,
            'safety_stock_locked' => (bool) $this->safety_stock_locked,
            'safety_stock_recomputed_at' => optional($this->safety_stock_recomputed_at)->toIso8601String(),
            'minimum_order_quantity' => (string) $this->minimum_order_quantity,
            'lead_time_days' => (int) $this->lead_time_days,
            'is_critical' => (bool) $this->is_critical,
            'is_active' => (bool) $this->is_active,
            'quality_plan_ready' => (bool) ($this->has_active_quality_plan ?? false),
            'abc_class' => $this->abc_class,
            'on_hand_quantity' => $onHand,
            'reserved_quantity' => $reserved,
            'available_quantity' => $available,
            'stock_status' => $stockStatus,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'deleted_at' => optional($this->deleted_at)?->toIso8601String(),
        ];
    }
}
