<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use App\Modules\Attendance\Enums\HolidayType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('attendance.holidays.manage') ?? false;
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
            'name'         => ['sometimes', 'string', 'max:100'],
            'date'         => ['sometimes', 'date', 'after:1900-01-01', 'before:2100-12-31'],
            'type'         => ['sometimes', Rule::in(HolidayType::values())],
            'is_recurring' => ['sometimes', 'boolean'],
        ];
    }
}
