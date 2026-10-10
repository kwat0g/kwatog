<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeShiftAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->hash_id,
            'employee_id' => $this->employee->hash_id,
            'employee_name' => $this->employee->full_name,
            'shift' => new ShiftResource($this->shift),
            'effective_date' => $this->effective_date->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'working_days' => $this->working_days,
        ];
    }
}
