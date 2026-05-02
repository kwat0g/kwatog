<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ItemCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $parent = $this->whenLoaded('parent', fn () => $this->parent);
        $hasParent = $this->relationLoaded('parent') && $this->parent;

        return [
            'id'        => $this->hash_id,
            'name'      => $this->name,
            'parent_id' => $hasParent ? $this->parent->hash_id : null,
            'parent_name' => $hasParent ? $this->parent->name : null,
            'children'  => self::collection($this->whenLoaded('children')),
            'created_at'=> optional($this->created_at)->toIso8601String(),
            'updated_at'=> optional($this->updated_at)->toIso8601String(),
        ];
    }
}
