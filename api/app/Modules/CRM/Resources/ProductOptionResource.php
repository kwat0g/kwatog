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
            'unit_of_measure' => $this->unit_of_measure,
            // Demand-page MRP toggle reads the flag from the dropdown payload
            // so forecast viewers need no full-product (cost-bearing) read.
            'include_forecast_in_mrp' => (bool) $this->include_forecast_in_mrp,
        ];
    }
}
