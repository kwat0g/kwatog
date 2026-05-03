<?php

declare(strict_types=1);

namespace App\Modules\HR\Requests;

use App\Modules\HR\Enums\SeparationReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InitiateSeparationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.separation.initiate');
    }

    public function rules(): array
    {
        return [
            'separation_date'   => ['required', 'date'],
            'separation_reason' => ['required', Rule::in(SeparationReason::values())],
            'remarks'           => ['nullable', 'string', 'max:5000'],
        ];
    }
}
