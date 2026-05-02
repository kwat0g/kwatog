<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->hash_id,
            'name'              => $this->name,
            'code'              => $this->code,
            // Avoid lazy-loading violations: only emit hashed IDs when the
            // owning relation is explicitly eager-loaded.
            'parent_id'         => $this->whenLoaded('parent', fn () => $this->parent?->hash_id),
            'parent'            => new self($this->whenLoaded('parent')),
            'head_employee_id'  => $this->whenLoaded('headEmployee', fn () => $this->headEmployee?->hash_id),
            'head_employee'     => $this->whenLoaded('headEmployee', fn () => $this->headEmployee ? [
                'id'        => $this->headEmployee->hash_id,
                'full_name' => $this->headEmployee->full_name,
            ] : null),
            'is_active'         => (bool) $this->is_active,
            'positions_count'   => $this->whenCounted('positions'),
            'employees_count'   => $this->whenCounted('employees'),
            'created_at'        => optional($this->created_at)->toIso8601String(),
            'updated_at'        => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
