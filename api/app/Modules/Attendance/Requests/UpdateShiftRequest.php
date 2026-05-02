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

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    public function rules(): array
    {
        $id = $this->route('shift')?->id;
        return [
            'name'           => ['sometimes', 'required', 'string', 'max:50', 'regex:/^[A-Za-z0-9\s\-_().]+$/', Rule::unique('shifts', 'name')->ignore($id)],
            'start_time'     => ['sometimes', 'date_format:H:i'],
            'end_time'       => ['sometimes', 'date_format:H:i', 'different:start_time'],
            'break_minutes'  => ['sometimes', 'integer', 'min:0', 'max:240'],
            'is_night_shift' => ['sometimes', 'boolean'],
            'is_extended'    => ['sometimes', 'boolean'],
            'auto_ot_hours'  => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:8'],
            'is_active'      => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex'         => 'Shift name may only contain letters, digits, spaces, and -_().',
            'end_time.different' => 'End time cannot be the same as start time.',
        ];
    }
}
