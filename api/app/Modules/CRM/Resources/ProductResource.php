<?php

declare(strict_types=1);

namespace App\Modules\CRM\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'part_number' => $this->part_number,
            'name' => $this->name,
            'description' => $this->description,
            'image_url' => $this->image_path ? "/api/v1/crm/products/{$this->hash_id}/image" : null,
            'unit_of_measure' => $this->unit_of_measure,
            'standard_cost' => (string) $this->standard_cost,
            'base_selling_price' => $this->base_selling_price,
            'minimum_selling_price' => $this->minimum_selling_price,
            'financial_change_request' => $this->financial_change_request ? array_replace($this->financial_change_request, ['proposed_by' => app('hashids')->encode((int) $this->financial_change_request['proposed_by'])]) : null,
            'portal_visibility' => $this->portal_visibility,
            'portal_customers' => $this->whenLoaded('portalCustomers', fn () => $this->portalCustomers->map(fn ($customer) => [
                'id' => $customer->hash_id, 'name' => $customer->name,
            ])->values()),
            'revenue_account_id' => $this->revenue_account_id
                ? app('hashids')->encode((int) $this->revenue_account_id)
                : null,
            'is_active' => (bool) $this->is_active,
            'include_forecast_in_mrp' => (bool) $this->include_forecast_in_mrp,
            'finished_good_readiness' => $this->whenLoaded('inventoryItem', fn () => $this->resource->finished_good_readiness),
            'has_bom' => $this->relationLoaded('activeBom')
                ? $this->activeBom !== null
                : (bool) ($this->has_bom_flag ?? false),
            'active_bom' => $this->whenLoaded('activeBom', fn () => $this->activeBom ? [
                'id' => $this->activeBom->hash_id,
                'version' => (int) $this->activeBom->version,
            ] : null),
            'inspection_spec' => $this->whenLoaded('inspectionSpec', fn () => $this->inspectionSpec ? [
                'id' => $this->inspectionSpec->hash_id,
                'version' => (int) $this->inspectionSpec->version,
                'updated_at' => optional($this->inspectionSpec->updated_at)->toIso8601String(),
            ] : null),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'deleted_at' => optional($this->deleted_at)?->toIso8601String(),
        ];
    }
}
