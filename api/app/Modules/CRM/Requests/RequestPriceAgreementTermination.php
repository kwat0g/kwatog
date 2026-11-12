<?php

declare(strict_types=1);

namespace App\Modules\CRM\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RequestPriceAgreementTermination extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $reason = $this->input('reason');
        if (is_string($reason)) {
            $this->merge(['reason' => trim($reason)]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('crm.price_agreements.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'last_valid_date' => ['nullable', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:10', 'max:1000', 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'],
            'coverage_gap_acknowledged' => ['sometimes', 'boolean'],
        ];
    }
}
