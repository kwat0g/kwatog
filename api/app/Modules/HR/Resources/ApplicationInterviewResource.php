<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationInterviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->hash_id,
            'scheduled_at'     => $this->scheduled_at?->toIso8601String(),
            'location'         => $this->location,
            'interviewer_name' => $this->interviewer_name,
            'notes'            => $this->notes,
            'outcome'          => $this->outcome?->value,
            'created_by'       => $this->whenLoaded('createdBy', fn () => [
                'id'   => $this->createdBy->hash_id,
                'name' => $this->createdBy->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
