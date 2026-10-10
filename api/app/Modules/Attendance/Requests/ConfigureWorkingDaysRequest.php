<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConfigureWorkingDaysRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('attendance.shifts.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'working_days' => ['required', 'array', 'list', 'min:1', 'max:7'],
            'working_days.*' => ['required', 'integer:strict', 'distinct:strict', 'between:1,7'],
        ];
    }
}
