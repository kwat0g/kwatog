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
            'outcome' => ['required', 'array:note,value,unit', 'max:3'],
            'outcome.note' => ['required_without:outcome.value', 'nullable', 'string', 'max:1000'],
            'outcome.value' => ['required_without:outcome.note', 'nullable', 'numeric', 'between:-999999999999.99,999999999999.99'],
            'outcome.unit' => ['nullable', 'string', 'max:20'],
        ];
    }
}
