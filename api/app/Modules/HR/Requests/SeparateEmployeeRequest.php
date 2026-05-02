<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SeparateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('hr.employees.separate') ?? false;
    }

    public function rules(): array
    {
        return [
            'separation_reason' => ['required', Rule::in(['resigned', 'terminated', 'retired', 'end_of_contract'])],
            'separation_date'   => ['required', 'date'],
            'remarks'           => ['nullable', 'string', 'max:2000'],
        ];
    }
}
