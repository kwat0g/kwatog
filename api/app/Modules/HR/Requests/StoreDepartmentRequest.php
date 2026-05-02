<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use App\Common\Traits\HasHashId;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;

class StoreDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('hr.departments.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'name'             => ['required', 'string', 'max:100'],
            'code'             => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9_-]+$/', 'unique:departments,code'],
            'parent_id'        => ['nullable', 'string'],
            'head_employee_id' => ['nullable', 'string'],
            'is_active'        => ['boolean'],
        ];
    }

    /** @return array<string,mixed> */
    public function validatedData(): array
    {
        $data = $this->validated();
        if (!empty($data['parent_id'])) {
            $data['parent_id'] = Department::tryDecodeHash($data['parent_id']);
        }
        if (!empty($data['head_employee_id'])) {
            $data['head_employee_id'] = Employee::tryDecodeHash($data['head_employee_id']);
        }
        return $data;
    }
}
