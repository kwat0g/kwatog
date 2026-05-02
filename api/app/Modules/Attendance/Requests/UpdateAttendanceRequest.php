<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use App\Modules\Attendance\Models\Shift;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('attendance.edit') ?? false;
    }

    public function rules(): array
    {
        return [
            'shift_id'    => ['sometimes', 'nullable', 'string'],
            'time_in'     => ['sometimes', 'nullable', 'date_format:H:i,H:i:s'],
            'time_out'    => ['sometimes', 'nullable', 'date_format:H:i,H:i:s'],
            'is_rest_day' => ['sometimes', 'boolean'],
            'remarks'     => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function validatedData(): array
    {
        $a = $this->route('attendance');
        $d = $this->validated();
        $date = optional($a?->date)->toDateString() ?? now()->toDateString();
        if (array_key_exists('shift_id', $d) && $d['shift_id']) {
            $d['shift_id'] = Shift::tryDecodeHash($d['shift_id']);
            abort_if(!$d['shift_id'], 422, 'Invalid shift.');
        }
        if (array_key_exists('time_in', $d) && $d['time_in']) {
            $d['time_in'] = $date.' '.substr($d['time_in'], 0, 5).':00';
        }
        if (array_key_exists('time_out', $d) && $d['time_out']) {
            $d['time_out'] = $date.' '.substr($d['time_out'], 0, 5).':00';
        }
        return $d;
    }
}
