<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('hr.employees.edit') ?? false;
    }

    public function rules(): array
    {
        return [
            'action'  => ['required', Rule::in(['approve', 'reject'])],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }
}
