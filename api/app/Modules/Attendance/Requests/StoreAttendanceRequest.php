<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use App\Modules\Attendance\Models\Shift;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;

class StoreAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('attendance.edit') ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'string'],
            'date'        => ['required', 'date'],
            'shift_id'    => ['nullable', 'string'],
            'time_in'     => ['nullable', 'date_format:H:i,H:i:s'],
            'time_out'    => ['nullable', 'date_format:H:i,H:i:s'],
            'is_rest_day' => ['boolean'],
            'remarks'     => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function validatedData(): array
    {
        $d = $this->validated();
        $d['employee_id'] = Employee::tryDecodeHash($d['employee_id']);
        abort_if(!$d['employee_id'], 422, 'Invalid employee.');
        if (!empty($d['shift_id'])) {
            $d['shift_id'] = Shift::tryDecodeHash($d['shift_id']);
            abort_if(!$d['shift_id'], 422, 'Invalid shift.');
        }
        // Combine date + time into ISO timestamps for Attendance model.
        $date = $d['date'];
        if (!empty($d['time_in']))  $d['time_in']  = $date.' '.substr($d['time_in'], 0, 5).':00';
        if (!empty($d['time_out'])) $d['time_out'] = $date.' '.substr($d['time_out'], 0, 5).':00';
        return $d;
    }
}
