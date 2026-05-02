<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('attendance.shifts.manage') ?? false;
    }

    public function rules(): array
    {
        $id = $this->route('shift')?->id;
        return [
            'name'           => ['sometimes', 'required', 'string', 'max:50', Rule::unique('shifts', 'name')->ignore($id)],
            'start_time'     => ['sometimes', 'date_format:H:i'],
            'end_time'       => ['sometimes', 'date_format:H:i'],
            'break_minutes'  => ['sometimes', 'integer', 'min:0', 'max:240'],
            'is_night_shift' => ['sometimes', 'boolean'],
            'is_extended'    => ['sometimes', 'boolean'],
            'auto_ot_hours'  => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:8'],
            'is_active'      => ['sometimes', 'boolean'],
        ];
    }
}
