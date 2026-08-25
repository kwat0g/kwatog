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
            'completed_at' => ['required', 'date'],
            'certificate' => [
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx',
                'max:10240',
            ],
        ];
    }
}
