<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use App\Modules\HR\Enums\EmploymentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateJobPostingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('hr.recruitment.manage');
    }

    public function rules(): array
    {
        return [
            'position_id'      => ['nullable', 'exists:positions,id'],
            'department_id'    => ['required', 'exists:departments,id'],
            'title'            => ['required', 'string', 'max:200'],
            'description'      => ['required', 'string'],
            'requirements'     => ['required', 'string'],
            'employment_type'  => ['required', Rule::enum(EmploymentType::class)],
            'salary_range_min' => ['nullable', 'decimal:0,2', 'min:0'],
            'salary_range_max' => ['nullable', 'decimal:0,2', 'min:0', 'gte:salary_range_min'],
            'show_salary'      => ['boolean'],
            'slots'            => ['integer', 'min:1', 'max:100'],
            'closes_at'        => ['nullable', 'date'],
        ];
    }
}
