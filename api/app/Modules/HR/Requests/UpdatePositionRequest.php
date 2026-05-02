<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use App\Modules\HR\Models\Department;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('hr.positions.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'title'         => ['sometimes', 'required', 'string', 'max:100'],
            'department_id' => ['sometimes', 'required', 'string'],
            'salary_grade'  => ['sometimes', 'nullable', 'string', 'max:20'],
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
