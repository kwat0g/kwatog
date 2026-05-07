<?php

declare(strict_types=1);

namespace App\Modules\HR\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProfileUpdateRequestResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'      => $this->hash_id,
            'status'  => $this->status,
            'changes' => $this->changes,
            'note'    => $this->note,
            'employee' => $this->relationLoaded('employee') && $this->employee ? [
                'id'          => $this->employee->hash_id,
                'employee_no' => $this->employee->employee_no,
                'full_name'   => $this->employee->full_name,
                'department'  => $this->employee->relationLoaded('department') && $this->employee->department
                    ? ['id' => $this->employee->department->hash_id, 'name' => $this->employee->department->name]
                    : null,
            ] : null,
            'requester' => $this->relationLoaded('requester') && $this->requester ? [
                'id'    => $this->requester->hash_id,
                'name'  => $this->requester->name,
                'email' => $this->requester->email,
            ] : null,
            'reviewer' => $this->relationLoaded('reviewer') && $this->reviewer ? [
                'id'    => $this->reviewer->hash_id,
                'name'  => $this->reviewer->name,
            ] : null,
            'reviewed_at'    => optional($this->reviewed_at)->toIso8601String(),
            'review_remarks' => $this->review_remarks,
            'created_at'     => optional($this->created_at)->toIso8601String(),
        ];
    }
}
