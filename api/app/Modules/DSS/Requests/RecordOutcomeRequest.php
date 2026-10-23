<?php

declare(strict_types=1);

namespace App\Modules\DSS\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordOutcomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('dss.view');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'outcome' => ['required', 'array'],
        ];
    }
}
