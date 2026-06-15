<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TrainingResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                => $this->hash_id,
            'name'              => $this->name,
            'description'       => $this->description,
            'duration_hours'    => $this->duration_hours,
            'validity_months'   => $this->validity_months,
            'is_certification'  => (bool) $this->is_certification,
            'is_active'         => (bool) $this->is_active,
            'department'        => $this->whenLoaded('department', fn() => $this->department ? [
                'id'   => $this->department->hash_id,
                'name' => $this->department->name,
            ] : null),
            'created_at'        => $this->created_at?->toISOString(),
            'updated_at'        => $this->updated_at?->toISOString(),
        ];
    }
}
