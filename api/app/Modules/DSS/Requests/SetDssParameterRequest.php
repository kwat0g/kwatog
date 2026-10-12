<?php

declare(strict_types=1);

namespace App\Modules\DSS\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetDssParameterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('dss.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'detector_id' => ['required', 'string', 'max:80'],
            'key' => ['required', 'string', 'max:100'],
            'value' => ['present'],
        ];
    }
}
