<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SuccessionPlanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                => $this->hash_id,
            'position'          => $this->whenLoaded('position', fn () => [
                'id'    => $this->position->hash_id,
                'title' => $this->position->title,
            ]),
            'incumbent'         => $this->whenLoaded('incumbent', fn () => $this->incumbent ? [
                'id'        => $this->incumbent->hash_id,
                'full_name' => $this->incumbent->first_name . ' ' . $this->incumbent->last_name,
            ] : null),
            'successor'         => $this->whenLoaded('successor', fn () => [
                'id'        => $this->successor->hash_id,
                'full_name' => $this->successor->first_name . ' ' . $this->successor->last_name,
            ]),
            'readiness'         => $this->readiness?->value,
            'priority'          => $this->priority?->value,
            'status'            => $this->status?->value,
            'development_notes' => $this->development_notes,
            'target_date'       => $this->target_date?->toDateString(),
            'created_at'        => $this->created_at?->toIso8601String(),
            'updated_at'        => $this->updated_at?->toIso8601String(),
        ];
    }
}
