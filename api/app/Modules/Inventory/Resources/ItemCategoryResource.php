<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->hash_id,
            'name'      => $this->name,
            'parent_id' => $this->parent_id ? $this->parent?->hash_id : null,
            'parent_name' => $this->parent?->name,
            'children'  => self::collection($this->whenLoaded('children')),
            'created_at'=> optional($this->created_at)->toIso8601String(),
            'updated_at'=> optional($this->updated_at)->toIso8601String(),
        ];
    }
}
