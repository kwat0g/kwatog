<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteEmployeeTrainingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.employees.trainings.manage');
    }

    public function rules(): array
    {
        return [
            'completed_at'     => ['required', 'date'],
            'certificate_path' => ['nullable', 'string', 'max:255'],
        ];
    }
}
