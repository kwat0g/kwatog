<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFxRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('accounting.currency.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'currency_code'      => ['required', 'string', 'size:3'],
            'rate_date'          => ['required', 'date'],
            'rate_to_functional' => ['required', 'numeric', 'gt:0'],
            'source'             => ['nullable', 'string', 'max:40'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('currency_code')) {
            $this->merge(['currency_code' => strtoupper((string) $this->input('currency_code'))]);
        }
    }
}
