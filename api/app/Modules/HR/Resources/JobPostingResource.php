<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JobPostingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->hash_id,
            'posting_number'  => $this->posting_number,
            'title'           => $this->title,
            'description'     => $this->description,
            'requirements'    => $this->requirements,
            'employment_type' => $this->employment_type?->value,
            'salary_range_min' => $this->salary_range_min,
            'salary_range_max' => $this->salary_range_max,
            'show_salary'     => $this->show_salary,
            'status'          => $this->status?->value,
            'slots'           => $this->slots,
            'posted_at'       => $this->posted_at?->toIso8601String(),
            'closes_at'       => $this->closes_at?->toIso8601String(),
            'position'        => $this->whenLoaded('position', fn () => [
                'id'    => $this->position->hash_id,
                'title' => $this->position->title,
            ]),
            'department' => $this->whenLoaded('department', fn () => [
                'id'   => $this->department->hash_id,
                'name' => $this->department->name,
            ]),
            'created_by' => $this->whenLoaded('createdBy', fn () => [
                'id'   => $this->createdBy->hash_id,
                'name' => $this->createdBy->name,
            ]),
            'application_count' => $this->when(
                $this->applications_count !== null,
                $this->applications_count
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
