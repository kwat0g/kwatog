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

    public function rules(): array
    {
        return [
            'name'         => ['sometimes', 'string', 'max:100'],
            'date'         => ['sometimes', 'date'],
            'type'         => ['sometimes', Rule::in(HolidayType::values())],
            'is_recurring' => ['sometimes', 'boolean'],
        ];
    }
}
