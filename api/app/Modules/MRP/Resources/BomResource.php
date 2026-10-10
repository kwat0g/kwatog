<?php

declare(strict_types=1);

namespace App\Modules\MRP\Resources;

use App\Common\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $completeness = $this->costed_at === null ? 'not_costed'
            : ($this->cost_basis === 'standard_cost' ? 'materials_only' : (count($this->cost_warnings ?? []) > 0 ? 'incomplete' : 'manufacturing_estimate'));
        $price = $this->relationLoaded('product') ? $this->product->base_selling_price : null;
        $blockingWarnings = array_filter($this->cost_warnings ?? [], fn ($warning) => ($warning['type'] ?? '') !== 'missing_routing');
        $canEstimate = $completeness !== 'incomplete' && $price !== null && Money::gt($price, '0') && $this->costed_at !== null
            && $this->total_cost !== null && Money::gt($this->total_cost, '0') && count($blockingWarnings) === 0;
        $contribution = $canEstimate ? Money::sub($price, $this->total_cost) : null;

        return [
            'id' => $this->hash_id,
            'cost_batch_size' => $this->cost_batch_size !== null ? (string) $this->cost_batch_size : '1.000',
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->hash_id,
                'part_number' => $this->product->part_number,
                'name' => $this->product->name,
                'unit_of_measure' => $this->product->unit_of_measure,
                'standard_cost' => $this->product->standard_cost,
                'base_selling_price' => $this->product->base_selling_price,
                'minimum_selling_price' => $this->product->minimum_selling_price,
                'finished_good_readiness' => $this->product->relationLoaded('inventoryItem') ? $this->product->finished_good_readiness : null,
            ]),
            'version' => (int) $this->version,
            'is_active' => (bool) $this->is_active,
            'item_count' => (int) ($this->items_count ?? $this->items?->count() ?? 0),
            'material_cost' => $this->material_cost !== null ? (string) $this->material_cost : null,
            'labor_cost' => $this->labor_cost !== null ? (string) $this->labor_cost : null,
            'machine_cost' => $this->machine_cost !== null ? (string) $this->machine_cost : null,
            'overhead_cost' => $this->overhead_cost !== null ? (string) $this->overhead_cost : null,
            'total_cost' => $this->total_cost !== null ? (string) $this->total_cost : null,
            'cost_completeness' => $completeness,
            'estimated_margin' => [
                'label' => $completeness === 'materials_only' ? 'Material contribution estimate' : 'Estimated gross margin',
                'selling_price' => $price, 'profit_per_unit' => $contribution,
                'margin_percent' => $contribution === null ? null : Money::round2(bcmul(bcdiv($contribution, $price, 8), '100', 6)),
                'markup_percent' => $contribution === null ? null : Money::round2(bcmul(bcdiv($contribution, (string) $this->total_cost, 8), '100', 6)),
            ],
            'cost_basis' => $this->cost_basis,
            'costed_at' => optional($this->costed_at)->toIso8601String(),
            'cost_warnings' => $this->cost_warnings ?? [],
            'items' => $this->whenLoaded('items', fn () => BomItemResource::collection($this->items)),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'deleted_at' => optional($this->deleted_at)?->toIso8601String(),
        ];
    }
}
