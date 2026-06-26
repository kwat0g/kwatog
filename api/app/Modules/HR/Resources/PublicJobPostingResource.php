<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicJobPostingResource extends JsonResource
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
            'salary_range'    => $this->show_salary ? [
                'min' => $this->salary_range_min,
                'max' => $this->salary_range_max,
            ] : null,
            'department' => [
                'id'   => $this->department->hash_id,
                'name' => $this->department->name,
            ],
            'posted_at'  => $this->posted_at?->toIso8601String(),
            'closes_at'  => $this->closes_at?->toIso8601String(),
        ];
    }
}
