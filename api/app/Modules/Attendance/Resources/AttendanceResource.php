<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->hash_id,
            'employee'          => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id'          => $this->employee->hash_id,
                'employee_no' => $this->employee->employee_no,
                'full_name'   => $this->employee->full_name,
                'department'  => $this->employee->department?->name,
            ] : null),
            'date'              => optional($this->date)->toDateString(),
            'shift'             => $this->whenLoaded('shift', fn () => $this->shift ? [
                'id'   => $this->shift->hash_id,
                'name' => $this->shift->name,
            ] : null),
            'time_in'           => optional($this->time_in)->toIso8601String(),
            'time_out'          => optional($this->time_out)->toIso8601String(),

            'regular_hours'      => (string) $this->regular_hours,
            'overtime_hours'     => (string) $this->overtime_hours,
            'night_diff_hours'   => (string) $this->night_diff_hours,
            'tardiness_minutes'  => (int) $this->tardiness_minutes,
            'undertime_minutes'  => (int) $this->undertime_minutes,

            'holiday_type'      => $this->holiday_type,
            'is_rest_day'       => (bool) $this->is_rest_day,
            'day_type_rate'     => (string) $this->day_type_rate,
            'status'            => $this->status?->value,
            'is_manual_entry'   => (bool) $this->is_manual_entry,
            'remarks'           => $this->remarks,

            'created_at'        => optional($this->created_at)->toIso8601String(),
            'updated_at'        => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
