<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('hr.positions.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if (is_string($this->input('title')))
            $merge['title'] = trim((string) $this->input('title'));
        if (is_string($this->input('salary_grade')))
            $merge['salary_grade'] = trim((string) $this->input('salary_grade'));
        if (!empty($merge)) $this->merge($merge);
    }

    public function rules(): array
    {
        return [
            'title'         => [
                'sometimes', 'required', 'string', 'max:100', "regex:/^[\\p{L}0-9\\s.&\\-,()\\/]+$/u",
                Rule::unique('positions', 'title')
                    ->ignore($this->route('position') instanceof Position ? $this->route('position')->getKey() : $this->route('position'))
                    ->where(function ($query) {
                        $department = $this->input('department_id');
                        if ($department === null && $this->route('position') instanceof Position) {
                            $department = $this->route('position')->department_id;
                        }

                        $departmentId = is_numeric($department)
                            ? (int) $department
                            : Department::tryDecodeHash((string) $department);

                        return $query
                            ->where('department_id', $departmentId)
                            ->whereNull('deleted_at');
                    }),
            ],
            'department_id' => ['sometimes', 'required', 'string'],
            'salary_grade'  => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.regex'        => 'Title may only contain letters, digits, spaces, and . & - , ( ) /',
            'salary_grade.regex' => 'Salary grade may only contain letters, digits, and hyphens.',
        ];
    }

    public function validatedData(): array
    {
        $data = $this->validated();
        if (array_key_exists('department_id', $data)) {
            $deptId = Department::tryDecodeHash($data['department_id']);
            abort_if($deptId === null, 422, 'Invalid department.');
            $data['department_id'] = $deptId;
        }
        return $data;
    }
}
