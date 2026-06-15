<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTrainingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.trainings.manage');
    }

    public function rules(): array
    {
        return [
            'name'             => ['sometimes', 'string', 'max:120'],
            'description'      => ['nullable', 'string'],
            'duration_hours'   => ['nullable', 'numeric', 'min:0'],
            'validity_months'  => ['nullable', 'integer', 'min:1', 'max:120'],
            'is_certification' => ['boolean'],
            'department_id'    => ['nullable', 'integer', 'exists:departments,id'],
            'is_active'        => ['boolean'],
        ];
    }
}
