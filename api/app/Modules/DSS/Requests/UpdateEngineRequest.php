<?php

declare(strict_types=1);

namespace App\Modules\DSS\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEngineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('dss.admin');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'default_mode' => ['sometimes', 'in:shadow'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['default_mode.in' => 'The global default mode must remain shadow; enable advisory mode per detector.'];
    }
}
