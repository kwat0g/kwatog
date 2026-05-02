<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use App\Modules\Attendance\Models\Shift;
use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Http\FormRequest;

class BulkAssignShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('attendance.shifts.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'department_id'  => ['required', 'string'],
            'shift_id'       => ['required', 'string'],
            'effective_date' => ['required', 'date'],
            'end_date'       => ['nullable', 'date', 'after_or_equal:effective_date'],
        ];
    }

    public function validatedData(): array
    {
        $d = $this->validated();
        $deptId = Department::tryDecodeHash($d['department_id']);
        $shiftId = Shift::tryDecodeHash($d['shift_id']);
        abort_if(!$deptId, 422, 'Invalid department.');
        abort_if(!$shiftId, 422, 'Invalid shift.');
        $d['department_id'] = $deptId;
        $d['shift_id'] = $shiftId;
        return $d;
    }
}
