<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReviewCycleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->hash_id,
            'name'          => $this->name,
            'cycle_type'    => $this->cycle_type?->value,
            'status'        => $this->status?->value,
            'start_date'    => $this->start_date?->toDateString(),
            'end_date'      => $this->end_date?->toDateString(),
            'description'   => $this->description,
            'reviews_count' => $this->whenCounted('reviews'),
            'created_at'    => $this->created_at?->toIso8601String(),
            'updated_at'    => $this->updated_at?->toIso8601String(),
        ];
    }
}
