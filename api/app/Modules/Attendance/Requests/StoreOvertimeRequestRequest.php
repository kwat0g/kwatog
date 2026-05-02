<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Requests;

use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;

class StoreOvertimeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'employee_id'      => ['required', 'string'],
            'date'             => ['required', 'date'],
            'hours_requested'  => ['required', 'numeric', 'min:0.5', 'max:8'],
            'reason'           => ['required', 'string', 'max:2000'],
        ];
    }

    public function validatedData(): array
    {
        $d = $this->validated();
        $d['employee_id'] = Employee::tryDecodeHash($d['employee_id']);
        abort_if(!$d['employee_id'], 422, 'Invalid employee.');
        return $d;
    }
}
