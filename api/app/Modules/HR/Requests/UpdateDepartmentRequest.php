<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('hr.departments.manage') ?? false;
    }

    public function rules(): array
    {
        $id = $this->route('department')?->id;
        return [
            'name'             => ['sometimes', 'required', 'string', 'max:100'],
            'code'             => ['sometimes', 'required', 'string', 'max:20', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('departments', 'code')->ignore($id)],
            'parent_id'        => ['sometimes', 'nullable', 'string'],
            'head_employee_id' => ['sometimes', 'nullable', 'string'],
            'is_active'        => ['sometimes', 'boolean'],
        ];
    }

    public function validatedData(): array
    {
        $data = $this->validated();
        if (array_key_exists('parent_id', $data)) {
            $data['parent_id'] = $data['parent_id'] ? Department::tryDecodeHash($data['parent_id']) : null;
        }
        if (array_key_exists('head_employee_id', $data)) {
            $data['head_employee_id'] = $data['head_employee_id'] ? Employee::tryDecodeHash($data['head_employee_id']) : null;
        }
        return $data;
    }
}
