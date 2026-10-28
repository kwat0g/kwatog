<?php

declare(strict_types=1);

namespace App\Modules\B2B\Requests\Customer;

use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePortalOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return ['items.*.quantity.regex' => 'Quantity must be a whole number of at least 1.'];
    }

    public function rules(): array
    {
        return [
            'quote_signature' => ['sometimes', 'nullable', 'string', 'size:64'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'payment_method' => ['sometimes', 'nullable', 'string', Rule::in(['cash', 'check', 'bank_transfer'])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'string', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'min:1', 'regex:/\A[0-9]+(?:\.0+)?\z/'],
            'items.*.delivery_date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
