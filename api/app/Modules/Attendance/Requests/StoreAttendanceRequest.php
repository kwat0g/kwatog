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
            // `date` alone accepted a full datetime, which validatedData() below
            // then concatenated with an H:i time, producing
            // "2026-04-15 12:00:00 08:00:00". Carbon refuses that with
            // "Double time specification", so a malformed input became a 500
            // instead of a field-keyed 422. The column is a DATE and the SPA
            // sends <input type="date">, so pinning the format costs nothing.
            'date'        => ['required', 'date_format:Y-m-d'],
            'shift_id'    => ['nullable', 'string'],
            'time_in'     => ['nullable', 'date_format:H:i,H:i:s'],
            'time_out'    => ['nullable', 'date_format:H:i,H:i:s'],
            'is_rest_day' => ['boolean'],
            'remarks'     => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.date_format' => 'Use a calendar date in YYYY-MM-DD format.',
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
