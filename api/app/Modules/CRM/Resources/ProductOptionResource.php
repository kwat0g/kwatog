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
            'unit_of_measure' => $this->unit_of_measure,
        ];
    }
}
