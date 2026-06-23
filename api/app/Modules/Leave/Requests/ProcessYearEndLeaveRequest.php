<?php

declare(strict_types=1);

namespace App\Modules\Leave\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProcessYearEndLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('leave.types.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'year' => ['sometimes', 'integer', 'min:2020', 'max:2099'],
        ];
    }
}
