<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PositionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->hash_id,
            'title'           => $this->title,
            // Avoid lazy-loading violation under strict mode by only emitting
            // the department hash when the relation is explicitly eager-loaded.
            'department_id'   => $this->whenLoaded('department', fn () => $this->department?->hash_id),
            'department'      => new DepartmentResource($this->whenLoaded('department')),
            'salary_grade'    => $this->salary_grade,
            'employees_count' => $this->whenCounted('employees'),
            'created_at'      => optional($this->created_at)->toIso8601String(),
            'updated_at'      => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
