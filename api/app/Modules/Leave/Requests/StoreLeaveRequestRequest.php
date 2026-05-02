<?php

declare(strict_types=1);

namespace App\Modules\Leave\Requests;

use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('leave.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'employee_id'   => ['required', 'string'],
            'leave_type_id' => ['required', 'string'],
            'start_date'    => ['required', 'date'],
            'end_date'      => ['required', 'date', 'after_or_equal:start_date'],
            'reason'        => ['nullable', 'string', 'max:2000'],
            'document_path' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function validatedData(): array
    {
        $d = $this->validated();
        $d['employee_id']   = Employee::tryDecodeHash($d['employee_id']);
        $d['leave_type_id'] = LeaveType::tryDecodeHash($d['leave_type_id']);
        abort_if(!$d['employee_id'], 422, 'Invalid employee.');
        abort_if(!$d['leave_type_id'], 422, 'Invalid leave type.');
        return $d;
    }
}
