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

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name'           => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9\s\-_().]+$/', 'unique:shifts,name'],
            'start_time'     => ['required', 'date_format:H:i'],
            'end_time'       => ['required', 'date_format:H:i', 'different:start_time'],
            'break_minutes'  => ['nullable', 'integer', 'min:0', 'max:240'],
            'is_night_shift' => ['boolean'],
            'is_extended'    => ['boolean'],
            'auto_ot_hours'  => ['nullable', 'numeric', 'min:0', 'max:8'],
            'is_active'      => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex'           => 'Shift name may only contain letters, digits, spaces, and -_().',
            'end_time.different'   => 'End time cannot be the same as start time.',
            'start_time.date_format' => 'Use HH:MM (24-hour) format.',
            'end_time.date_format'   => 'Use HH:MM (24-hour) format.',
        ];
    }
}
