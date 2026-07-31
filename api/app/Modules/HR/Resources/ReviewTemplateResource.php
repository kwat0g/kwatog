<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->hash_id,
            'name'        => $this->name,
            'description' => $this->description,
            'criteria'    => $this->criteria,
            'is_active'   => (bool) $this->is_active,
            'created_at'  => $this->created_at?->toIso8601String(),
            'updated_at'  => $this->updated_at?->toIso8601String(),
        ];
    }
}
