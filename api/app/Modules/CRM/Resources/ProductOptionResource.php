<?php

declare(strict_types=1);

namespace App\Modules\CRM\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'part_number' => $this->part_number,
            'name' => $this->name,
            'image_url' => $this->image_path ? "/api/v1/crm/products/{$this->hash_id}/image" : null,
            'primary_image_url' => $this->image_path ? "/api/v1/crm/products/{$this->hash_id}/image" : null,
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($image) => [
                'id' => $image->hash_id,
                'url' => "/api/v1/crm/products/{$this->hash_id}/images/{$image->hash_id}/file",
                'position' => (int) $image->position,
                'alt_text' => $image->alt_text,
                'is_primary' => (bool) $image->is_primary,
            ])->values()),
            'specifications' => $this->specifications,
            'is_customizable' => (bool) $this->is_customizable,
            'unit_of_measure' => $this->unit_of_measure,
            // Demand-page MRP toggle reads the flag from the dropdown payload
            // so forecast viewers need no full-product (cost-bearing) read.
            'include_forecast_in_mrp' => (bool) $this->include_forecast_in_mrp,
        ];
    }
}
