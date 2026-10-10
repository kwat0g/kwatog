<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use App\Modules\Attendance\Models\Shift;
use Illuminate\Foundation\Http\FormRequest;

class AssignEmployeeShiftRequest extends FormRequest
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
            'shift_id' => ['required', 'string'],
            'effective_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:effective_date'],
        ];
    }

    public function validatedData(): array
    {
        $d = $this->validated();
        $shiftId = Shift::tryDecodeHash($d['shift_id']);
        abort_if(! $shiftId, 422, 'Invalid shift.');
        $d['shift_id'] = $shiftId;

        return $d;
    }
}
