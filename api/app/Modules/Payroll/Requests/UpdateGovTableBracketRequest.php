<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGovTableBracketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('admin.gov_tables.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'bracket_min'    => ['sometimes', 'numeric', 'min:0', 'max:9999999.99'],
            'bracket_max'    => ['sometimes', 'numeric', 'min:0', 'max:9999999.99', 'gte:bracket_min'],
            'ee_amount'      => ['sometimes', 'numeric', 'min:0', 'max:9999999.9999'],
            'er_amount'      => ['sometimes', 'numeric', 'min:0', 'max:9999999.9999'],
            'effective_date' => ['sometimes', 'date'],
            'is_active'      => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'bracket_max.gte' => 'Maximum must be greater than or equal to minimum.',
        ];
    }
}
