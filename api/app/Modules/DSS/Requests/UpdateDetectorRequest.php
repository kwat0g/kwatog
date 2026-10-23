<?php

declare(strict_types=1);

namespace App\Modules\DSS\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDetectorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('dss.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'mode' => ['sometimes', Rule::in(['shadow', 'advisory'])],
        ];
    }
}
