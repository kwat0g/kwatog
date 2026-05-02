<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OvertimeRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->hash_id,
            'employee'        => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id'          => $this->employee->hash_id,
                'employee_no' => $this->employee->employee_no,
                'full_name'   => $this->employee->full_name,
            ] : null),
            'date'            => optional($this->date)->toDateString(),
            'hours_requested' => (string) $this->hours_requested,
            'reason'          => $this->reason,
            'status'          => $this->status?->value,
            'approver'        => $this->whenLoaded('approver', fn () => $this->approver ? [
                'id'   => $this->approver->hash_id,
                'name' => $this->approver->name,
            ] : null),
            'rejection_reason'=> $this->rejection_reason,
            'created_at'      => optional($this->created_at)->toIso8601String(),
            'updated_at'      => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
