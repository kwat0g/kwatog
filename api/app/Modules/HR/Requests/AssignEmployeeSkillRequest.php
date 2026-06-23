<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignEmployeeSkillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.employees.trainings.manage');
    }

    public function rules(): array
    {
        return [
            'skill_id'                    => ['required', 'string'],
            'proficiency_level'           => ['required', Rule::in(['novice', 'competent', 'proficient', 'expert', 'trainer'])],
            'acquired_date'               => ['required', 'date'],
            'expires_at'                  => ['nullable', 'date', 'after_or_equal:acquired_date'],
            'certified_by'                => ['nullable', 'string'],
            'certification_document_path' => ['nullable', 'string', 'max:255'],
            'notes'                       => ['nullable', 'string'],
        ];
    }
}
