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

    protected function prepareForValidation(): void
    {
        $merge = [];
        if (is_string($this->input('name')))
            $merge['name'] = trim((string) $this->input('name'));
        if (is_string($this->input('code')))
            $merge['code'] = strtoupper(trim((string) $this->input('code')));
        if (!empty($merge)) $this->merge($merge);
    }

    public function rules(): array
    {
        $id = $this->route('department')?->id;
        return [
            'name'             => ['sometimes', 'required', 'string', 'max:100', "regex:/^[\\p{L}0-9\\s.&\\-,()]+$/u"],
            'code'             => ['sometimes', 'required', 'string', 'max:20', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('departments', 'code')->ignore($id)],
            'parent_id'        => ['sometimes', 'nullable', 'string'],
            'head_employee_id' => ['sometimes', 'nullable', 'string'],
            'is_active'        => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'Name may only contain letters, digits, spaces, and . & - , ( )',
            'code.regex' => 'Code may only contain uppercase letters, digits, underscores, and hyphens.',
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
