<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemCatalogOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'code' => $this->code,
            'name' => $this->name,
            'unit_of_measure' => $this->unit_of_measure,
        ];
    }
}
