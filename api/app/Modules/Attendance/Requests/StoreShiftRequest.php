<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('attendance.shifts.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name'           => ['required', 'string', 'max:50', 'unique:shifts,name'],
            'start_time'     => ['required', 'date_format:H:i'],
            'end_time'       => ['required', 'date_format:H:i'],
            'break_minutes'  => ['nullable', 'integer', 'min:0', 'max:240'],
            'is_night_shift' => ['boolean'],
            'is_extended'    => ['boolean'],
            'auto_ot_hours'  => ['nullable', 'numeric', 'min:0', 'max:8'],
            'is_active'      => ['boolean'],
        ];
    }
}
