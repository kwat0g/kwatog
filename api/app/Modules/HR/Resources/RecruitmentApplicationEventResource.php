<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecruitmentApplicationEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'event_type' => $this->event_type,
            'actor_type' => $this->actor_type,
            'actor' => $this->whenLoaded('actor', fn () => $this->actor ? [
                'id' => $this->actor->hash_id,
                'name' => $this->actor->name,
            ] : null),
            'from_stage' => $this->from_stage,
            'to_stage' => $this->to_stage,
            'before' => $this->before_values,
            'after' => $this->after_values,
            'metadata' => $this->metadata,
            'correlation_id' => $this->correlation_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
