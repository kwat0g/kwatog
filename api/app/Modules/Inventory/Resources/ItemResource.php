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
        $available = (string) ($attributes['usable_quantity'] ?? $available);

        $reorder = (string) $this->reorder_point;
        $safety = (string) $this->safety_stock;
        $stockStatus = bccomp($available, $safety, 3) <= 0 ? 'critical'
            : (bccomp($available, $reorder, 3) <= 0 ? 'low' : 'ok');

        return [
            'id' => $this->hash_id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->whenLoaded('category', fn () => $this->category ? ['id' => $this->category->hash_id, 'name' => $this->category->name, 'item_type' => $this->category->item_type] : null
            ),
            'item_type' => (string) $this->item_type?->value,
            'item_type_label' => $this->item_type?->label(),
            'classification_needs_review' => $this->whenLoaded('category', fn () => ! $this->category || $this->category->item_type !== $this->item_type?->value),
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
            'quality_held_quantity' => (string) ($attributes['quality_held_quantity'] ?? '0.000'),
            'unusable_zone_quantity' => (string) ($attributes['unusable_zone_quantity'] ?? '0.000'),
            'usable_quantity' => $available,
            'available_quantity' => $available,
            'approved_free_stock' => $this->when(array_key_exists('approved_free_stock', $attributes), fn () => $attributes['approved_free_stock']),
            'incoming_stock_quantity' => $this->when(array_key_exists('incoming_stock_quantity', $attributes), fn () => $attributes['incoming_stock_quantity']),
            'stock_status' => $stockStatus,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'deleted_at' => optional($this->deleted_at)?->toIso8601String(),
        ];
    }
}
