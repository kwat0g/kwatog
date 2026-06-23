<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeSkillResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                => $this->hash_id,
            'employee'          => $this->whenLoaded('employee', fn() => $this->employee ? [
                'id'        => $this->employee->hash_id,
                'full_name' => $this->employee->full_name,
            ] : null),
            'skill'             => $this->whenLoaded('skill', fn() => $this->skill ? [
                'id'       => $this->skill->hash_id,
                'name'     => $this->skill->name,
                'category' => $this->skill->category,
            ] : null),
            'proficiency_level'  => $this->proficiency_level?->value,
            'acquired_date'      => $this->acquired_date?->toDateString(),
            'expires_at'         => $this->expires_at?->toDateString(),
            'certified_by'       => $this->whenLoaded('certifier', fn() => $this->certifier ? [
                'id'   => $this->certifier->hash_id,
                'name' => $this->certifier->name,
            ] : null),
            'certification_document_path' => $this->certification_document_path,
            'notes'                       => $this->notes,
            'created_at'                  => $this->created_at?->toISOString(),
            'updated_at'                  => $this->updated_at?->toISOString(),
        ];
    }
}
