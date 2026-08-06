<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Modules\HR\Enums\EmployeeSkillLevel;

class UpdateEmployeeSkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.employees.trainings.manage');
    }

    public function rules(): array
    {
        return [
            'proficiency_level'           => ['sometimes', 'required', Rule::enum(EmployeeSkillLevel::class)],
            'acquired_date'               => ['sometimes', 'required', 'date'],
            'expires_at'                  => ['nullable', 'date', 'after_or_equal:acquired_date'],
            'certified_by'                => ['nullable', 'string'],
            'certification_document_path' => ['nullable', 'string', 'max:255'],
            'notes'                       => ['nullable', 'string'],
        ];
    }
}
