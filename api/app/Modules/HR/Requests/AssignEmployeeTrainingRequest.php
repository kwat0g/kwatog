<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignEmployeeTrainingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.employees.trainings.manage');
    }

    public function rules(): array
    {
        return [
            'training_id'   => ['required', 'string'],
            'scheduled_for' => ['nullable', 'date'],
            'notes'         => ['nullable', 'string'],
        ];
    }
}
