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
            'id'              => $this->hash_id,
            'part_number'     => $this->part_number,
            'name'            => $this->name,
            'description'     => $this->description,
            'unit_of_measure' => $this->unit_of_measure,
            'standard_cost'   => (string) $this->standard_cost,
            'is_active'       => (bool) $this->is_active,
            'has_bom'         => (bool) ($this->has_bom_flag ?? false),
            'created_at'      => optional($this->created_at)->toIso8601String(),
            'updated_at'      => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
