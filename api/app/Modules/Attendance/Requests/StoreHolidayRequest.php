<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use App\Modules\Attendance\Enums\HolidayType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('attendance.holidays.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name'         => ['required', 'string', 'max:100'],
            'date'         => ['required', 'date'],
            'type'         => ['required', Rule::in(HolidayType::values())],
            'is_recurring' => ['boolean'],
        ];
    }
}
