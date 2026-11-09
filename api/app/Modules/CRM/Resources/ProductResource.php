<?php

declare(strict_types=1);

namespace App\Modules\CRM\Resources;

use App\Common\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $canViewCosts = $request->user()?->hasPermission('inventory.costs.review')
            || $request->user()?->hasPermission('inventory.costs.approve');
        $financialChange = $this->financial_change_request;
        $sellingPrice = $this->base_selling_price;
        $expectedMarginAmount = $canViewCosts && $sellingPrice !== null && $this->standard_cost !== null
            ? Money::sub((string) $sellingPrice, (string) $this->standard_cost)
            : null;
        $expectedMarginPercent = $canViewCosts && $sellingPrice !== null && Money::gt((string) $sellingPrice, '0') && $expectedMarginAmount !== null
            ? bcdiv(bcmul($expectedMarginAmount, '100', 4), (string) $sellingPrice, 2)
            : null;

        return [
            'id' => $this->hash_id,
            'part_number' => $this->part_number,
            'name' => $this->name,
            'description' => $this->description,
            'image_url' => $this->image_path ? "/api/v1/crm/products/{$this->hash_id}/image" : null,
            'primary_image_url' => $this->image_path ? "/api/v1/crm/products/{$this->hash_id}/image" : null,
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($image) => [
                'id' => $image->hash_id,
                'url' => "/api/v1/crm/products/{$this->hash_id}/images/{$image->hash_id}/file",
                'filename' => $image->filename,
                'mime' => $image->mime,
                'size' => $image->size,
                'position' => $image->position,
                'alt_text' => $image->alt_text,
                'is_primary' => (bool) $image->is_primary,
            ])->values()),
            'unit_of_measure' => $this->unit_of_measure,
            'standard_cost' => $canViewCosts ? (string) $this->standard_cost : null,
            'expected_margin_amount' => $canViewCosts ? $expectedMarginAmount : null,
            'expected_margin_percent' => $canViewCosts ? $expectedMarginPercent : null,
            'base_selling_price' => $this->base_selling_price,
            'minimum_selling_price' => $this->minimum_selling_price,
            'financial_change_request' => $canViewCosts && $financialChange ? array_replace($financialChange, [
                'values' => array_intersect_key($financialChange['values'] ?? [], ['standard_cost' => true]),
                'original' => array_intersect_key($financialChange['original'] ?? [], ['standard_cost' => true]),
                'proposed_by' => app('hashids')->encode((int) $financialChange['proposed_by']),
            ]) : null,
            'portal_visibility' => $this->portal_visibility,
            'specifications' => $this->specifications,
            'is_customizable' => (bool) $this->is_customizable,
            'blueprints' => $this->whenLoaded('blueprints', fn () => $this->blueprints->map(fn ($blueprint) => [
                'id' => $blueprint->hash_id,
                'title' => $blueprint->title,
                'revision' => $blueprint->revision,
                'notes' => $blueprint->notes,
                'filename' => $blueprint->filename,
                'mime' => $blueprint->mime,
                'size' => $blueprint->size,
                'download_url' => "/api/v1/crm/products/{$this->hash_id}/blueprints/{$blueprint->hash_id}/file",
                'created_at' => optional($blueprint->created_at)->toIso8601String(),
            ])->values()),
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
