<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $onHand   = (float) ($this->on_hand_quantity   ?? $this->stockLevels()->sum('quantity'));
        $reserved = (float) ($this->reserved_quantity  ?? $this->stockLevels()->sum('reserved_quantity'));
        $available = max(0.0, $onHand - $reserved);

        $reorder = (float) $this->reorder_point;
        $safety  = (float) $this->safety_stock;
        $stockStatus = $available <= $safety ? 'critical' : ($available <= $reorder ? 'low' : 'ok');

        return [
            'id'                     => $this->hash_id,
            'code'                   => $this->code,
            'name'                   => $this->name,
            'description'            => $this->description,
            'category'               => $this->whenLoaded('category', fn () =>
                $this->category ? ['id' => $this->category->hash_id, 'name' => $this->category->name] : null
            ),
            'item_type'              => (string) $this->item_type?->value,
            'item_type_label'        => $this->item_type?->label(),
            'unit_of_measure'        => $this->unit_of_measure,
            'standard_cost'          => (string) $this->standard_cost,
            'reorder_method'         => (string) $this->reorder_method?->value,
            'reorder_point'          => (string) $this->reorder_point,
            'safety_stock'           => (string) $this->safety_stock,
            'minimum_order_quantity' => (string) $this->minimum_order_quantity,
            'lead_time_days'         => (int) $this->lead_time_days,
            'is_critical'            => (bool) $this->is_critical,
            'is_active'              => (bool) $this->is_active,
            'on_hand_quantity'       => number_format($onHand, 3, '.', ''),
            'reserved_quantity'      => number_format($reserved, 3, '.', ''),
            'available_quantity'     => number_format($available, 3, '.', ''),
            'stock_status'           => $stockStatus,
            'created_at'             => optional($this->created_at)->toIso8601String(),
            'updated_at'             => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
